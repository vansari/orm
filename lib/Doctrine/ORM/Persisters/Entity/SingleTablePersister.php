<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Entity;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Internal\SQLResultCasing;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Utility\PersisterHelper;

use function array_flip;
use function implode;

/**
 * Persister for entities that participate in a hierarchy mapped with the
 * SINGLE_TABLE strategy.
 *
 * @link https://martinfowler.com/eaaCatalog/singleTableInheritance.html
 */
class SingleTablePersister extends AbstractEntityInheritancePersister
{
    use SQLResultCasing;

    /**
     * {@inheritdoc}
     */
    protected function getDiscriminatorColumnTableName()
    {
        return $this->class->getTableName();
    }

    /**
     * {@inheritdoc}
     */
    protected function getSelectColumnsSQL()
    {
        if ($this->currentPersisterContext->selectColumnListSql !== null) {
            return $this->currentPersisterContext->selectColumnListSql;
        }

        $columnList[] = parent::getSelectColumnsSQL();

        $rootClass  = $this->em->getClassMetadata($this->class->rootEntityName);
        $tableAlias = $this->getSQLTableAlias($rootClass->name);

        // Append discriminator column
        $discrColumn     = $this->class->getDiscriminatorColumn();
        $discrColumnName = $discrColumn['name'];
        $discrColumnType = $discrColumn['type'];

        $columnList[] = $tableAlias . '.' . $discrColumnName;

        $resultColumnName = $this->getSQLResultCasing($this->platform, $discrColumnName);

        $this->currentPersisterContext->rsm->setDiscriminatorColumn('r', $resultColumnName);
        $this->currentPersisterContext->rsm->addMetaResult('r', $resultColumnName, $discrColumnName, false, $discrColumnType);

        // Append subclass columns
        foreach ($this->class->subClasses as $subClassName) {
            $subClass = $this->em->getClassMetadata($subClassName);

            // Regular columns
            foreach ($subClass->fieldMappings as $fieldName => $mapping) {
                if (isset($mapping['inherited'])) {
                    continue;
                }

                $columnList[] = $this->getSelectColumnSQL($fieldName, $subClass);
            }

            // Foreign key columns
            foreach ($subClass->associationMappings as $assoc) {
                if (! $assoc['isOwningSide'] || ! ($assoc['type'] & ClassMetadata::TO_ONE) || isset($assoc['inherited'])) {
                    continue;
                }

                $targetClass = $this->em->getClassMetadata($assoc['targetEntity']);

                foreach ($assoc['joinColumns'] as $joinColumn) {
                    $columnList[] = $this->getSelectJoinColumnSQL(
                        $tableAlias,
                        $joinColumn['name'],
                        $this->quoteStrategy->getJoinColumnName($joinColumn, $subClass, $this->platform),
                        PersisterHelper::getTypeOfColumn($joinColumn['referencedColumnName'], $targetClass, $this->em)
                    );
                }
            }
        }

        $this->currentPersisterContext->selectColumnListSql = implode(', ', $columnList);

        return $this->currentPersisterContext->selectColumnListSql;
    }

    /**
     * {@inheritdoc}
     */
    protected function getInsertColumnList()
    {
        $columns = parent::getInsertColumnList();

        // Add discriminator column to the INSERT SQL
        $discriminatorColumn = $this->class->getDiscriminatorColumn();
        if (false === in_array($discriminatorColumn['generated'], ['INSERT', 'ALWAYS'])) {
            $columns[] = $discriminatorColumn['name'];
        }

        return $columns;
    }

    /**
     * {@inheritdoc}
     */
    protected function getSQLTableAlias($className, $assocName = '')
    {
        return parent::getSQLTableAlias($this->class->rootEntityName, $assocName);
    }

    /**
     * {@inheritdoc}
     */
    protected function getSelectConditionSQL(array $criteria, $assoc = null)
    {
        $conditionSql = parent::getSelectConditionSQL($criteria, $assoc);

        if ($conditionSql) {
            $conditionSql .= ' AND ';
        }

        return $conditionSql . $this->getSelectConditionDiscriminatorValueSQL();
    }

    /**
     * {@inheritdoc}
     */
    protected function getSelectConditionCriteriaSQL(Criteria $criteria)
    {
        $conditionSql = parent::getSelectConditionCriteriaSQL($criteria);

        if ($conditionSql) {
            $conditionSql .= ' AND ';
        }

        return $conditionSql . $this->getSelectConditionDiscriminatorValueSQL();
    }

    /** @return string */
    protected function getSelectConditionDiscriminatorValueSQL()
    {
        $values = [];

        if ($this->class->discriminatorValue !== null) { // discriminators can be 0
            $values[] = $this->conn->quote($this->class->discriminatorValue);
        }

        $discrValues = array_flip($this->class->discriminatorMap);

        foreach ($this->class->subClasses as $subclassName) {
            $values[] = $this->conn->quote($discrValues[$subclassName]);
        }

        $discColumnName = $this->class->getDiscriminatorColumn()['name'];

        $values     = implode(', ', $values);
        $tableAlias = $this->getSQLTableAlias($this->class->name);

        return $tableAlias . '.' . $discColumnName . ' IN (' . $values . ')';
    }

    /**
     * {@inheritdoc}
     */
    protected function generateFilterConditionSQL(ClassMetadata $targetEntity, $targetTableAlias)
    {
        // Ensure that the filters are applied to the root entity of the inheritance tree
        $targetEntity = $this->em->getClassMetadata($targetEntity->rootEntityName);
        // we don't care about the $targetTableAlias, in a STI there is only one table.

        return parent::generateFilterConditionSQL($targetEntity, $targetTableAlias);
    }
}
