<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Persisters;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Common\Collections\Criteria;

/**
 * Persister for entities that participate in a hierarchy mapped with the
 * SINGLE_TABLE strategy.
 *
 * @author Roman Borschel <roman@code-factory.org>
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Alexander <iam.asm89@gmail.com>
 * @since 2.0
 * @link http://martinfowler.com/eaaCatalog/singleTableInheritance.html
 */
class SingleTablePersister extends AbstractEntityInheritancePersister
{
    /** {@inheritdoc} */
    protected function getDiscriminatorColumnTableName()
    {
        return $this->class->getTableName();
    }

    /** {@inheritdoc} */
    protected function getSelectColumnListSQL()
    {
        if ($this->selectColumnListSql !== null) {
            return $this->selectColumnListSql;
        }

        $columnList = parent::getSelectColumnListSQL();

        $rootClass  = $this->em->getClassMetadata($this->class->rootEntityName);
        $tableAlias = $this->getSQLTableAlias($rootClass->name);

         // Append discriminator column
        $discrColumn = $this->class->discriminatorColumn['name'];
        $columnList .= ', ' . $tableAlias . '.' . $discrColumn;

        $resultColumnName = $this->platform->getSQLResultCasing($discrColumn);

        $this->rsm->setDiscriminatorColumn('r', $resultColumnName);
        $this->rsm->addMetaResult('r', $resultColumnName, $discrColumn);

        // Append subclass columns
        foreach ($this->class->subClasses as $subClassName) {
            $subClass = $this->em->getClassMetadata($subClassName);

            // Regular columns
            foreach ($subClass->fieldMappings as $fieldName => $mapping) {
                if ( ! isset($mapping['inherited'])) {
                    $columnList .= ', ' . $this->getSelectColumnSQL($fieldName, $subClass);
                }
            }

            // Foreign key columns
            foreach ($subClass->associationMappings as $assoc) {
                if ($assoc['isOwningSide'] && $assoc['type'] & ClassMetadata::TO_ONE && ! isset($assoc['inherited'])) {
                    foreach ($assoc['targetToSourceKeyColumns'] as $srcColumn) {
                        if ($columnList != '') $columnList .= ', ';

                        $columnList .= $this->getSelectJoinColumnSQL(
                            $tableAlias,
                            $srcColumn,
                            isset($assoc['inherited']) ? $assoc['inherited'] : $this->class->name
                        );
                    }
                }
            }
        }

        $this->selectColumnListSql = $columnList;
        return $this->selectColumnListSql;
    }

    /** {@inheritdoc} */
    protected function getInsertColumnList()
    {
        $columns = parent::getInsertColumnList();

        // Add discriminator column to the INSERT SQL
        $columns[] = $this->class->discriminatorColumn['name'];

        return $columns;
    }

    /** {@inheritdoc} */
    protected function getSQLTableAlias($className, $assocName = '')
    {
        return parent::getSQLTableAlias($this->class->rootEntityName, $assocName);
    }

    /** {@inheritdoc} */
    protected function getSelectConditionSQL(array $criteria, $assoc = null)
    {
        $conditionSql = parent::getSelectConditionSQL($criteria, $assoc);

        if ($conditionSql) {
            $conditionSql .= ' AND ';
        }

        return $conditionSql . $this->getSelectConditionDiscriminatorValueSQL();
    }

    /** {@inheritdoc} */
    protected function getSelectConditionCriteriaSQL(Criteria $criteria)
    {
        $conditionSql = parent::getSelectConditionCriteriaSQL($criteria);

        if ($conditionSql) {
            $conditionSql .= ' AND ';
        }

        return $conditionSql . $this->getSelectConditionDiscriminatorValueSQL();
    }

    protected function getSelectConditionDiscriminatorValueSQL()
    {
        $values = array();

        if ($this->class->discriminatorValue !== null) { // discriminators can be 0
            $values[] = $this->conn->quote($this->class->discriminatorValue);
        }

        $discrValues = array_flip($this->class->discriminatorMap);

        foreach ($this->class->subClasses as $subclassName) {
            $values[] = $this->conn->quote($discrValues[$subclassName]);
        }

        return $this->getSQLTableAlias($this->class->name) . '.' . $this->class->discriminatorColumn['name']
                . ' IN (' . implode(', ', $values) . ')';
    }

    /** {@inheritdoc} */
    protected function generateFilterConditionSQL(ClassMetadata $targetEntity, $targetTableAlias)
    {
        // Ensure that the filters are applied to the root entity of the inheritance tree
        $targetEntity = $this->em->getClassMetadata($targetEntity->rootEntityName);
        // we dont care about the $targetTableAlias, in a STI there is only one table.

        return parent::generateFilterConditionSQL($targetEntity, $targetTableAlias);
    }
}
