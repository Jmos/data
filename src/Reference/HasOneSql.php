<?php

declare(strict_types=1);

namespace Atk4\Data\Reference;

use Atk4\Data\Field\SqlExpressionField;
use Atk4\Data\Model;

class HasOneSql extends HasOne
{
    /**
     * @param ($theirFieldIsTitle is true ? null : string) $theirFieldName
     */
    private function _addField(string $fieldName, bool $theirFieldIsTitle, ?string $theirFieldName, array $defaults): SqlExpressionField
    {
        $ourModel = $this->getOurModel(null);

        $fieldExpression = $ourModel->addExpression($fieldName, array_merge([
            'expr' => function (Model $ourModel) use ($theirFieldIsTitle, $theirFieldName) {
                $theirModel = $ourModel->refLink($this->link);
                if ($theirFieldIsTitle) {
                    $theirFieldName = $theirModel->title_field;
                }

                // remove order if we just select one field from hasOne model, needed for Oracle
                return $theirModel->action('field', [$theirFieldName])->reset('order');
            },
        ], $defaults, [
            // to be able to change field, but not save it
            // afterSave hook will take care of the rest
            'read_only' => false,
            'never_save' => true,
        ]));

        $this->onHookToOurModel($ourModel, Model::HOOK_BEFORE_SAVE, function (Model $ourModel) use ($fieldName, $theirFieldIsTitle, $theirFieldName) {
            // if field is changed, but reference ID field (our_field)
            // is not changed, then update reference ID field value
            if ($ourModel->isDirty($fieldName) && !$ourModel->isDirty($this->our_field)) {
                $theirModel = $this->createTheirModel();
                if ($theirFieldIsTitle) {
                    $theirFieldName = $theirModel->title_field;
                }

                $theirModel->addCondition($theirFieldName, $ourModel->get($fieldName));
                $ourModel->set($this->getOurFieldName(), $theirModel->loadOne()->getId());
                if (!$theirFieldIsTitle) { // why for non-title only?
                    $ourModel->_unset($fieldName);
                }
            }
        }, [], 20);

        return $fieldExpression;
    }

    /**
     * Creates expression which sub-selects a field inside related model.
     */
    public function addField(string $fieldName, string $theirFieldName = null, array $defaults = []): SqlExpressionField
    {
        if ($theirFieldName === null) {
            $theirFieldName = $fieldName;
        }

        $ourModel = $this->getOurModel(null);

        // if caption/type is not defined in $defaults -> get it directly from the linked model field $theirFieldName
        $refModelField = $ourModel->refModel($this->link)->getField($theirFieldName);
        $defaults['type'] ??= $refModelField->type;
        $defaults['enum'] ??= $refModelField->enum;
        $defaults['values'] ??= $refModelField->values;
        $defaults['caption'] ??= $refModelField->caption;
        $defaults['ui'] ??= $refModelField->ui;

        $fieldExpression = $this->_addField($fieldName, false, $theirFieldName, $defaults);

        return $fieldExpression;
    }

    /**
     * Add multiple expressions by calling addField several times. Fields
     * may contain 3 types of elements:.
     *
     * ['name', 'surname'] - will import those fields as-is
     * ['full_name' => 'name', 'day_of_birth' => ['dob', 'type' => 'date']] - use alias and options
     * [['dob', 'type' => 'date']]  - use options
     *
     * @return $this
     */
    public function addFields(array $fields = [], array $defaults = [])
    {
        foreach ($fields as $ourFieldName => $ourFieldDefaults) {
            $ourFieldDefaults = array_merge($defaults, (array) $ourFieldDefaults);

            $theirFieldName = $ourFieldDefaults[0] ?? null;
            unset($ourFieldDefaults[0]);
            if (is_int($ourFieldName)) {
                $ourFieldName = $theirFieldName;
            }

            $this->addField($ourFieldName, $theirFieldName, $ourFieldDefaults);
        }

        return $this;
    }

    /**
     * Creates model that can be used for generating sub-query actions.
     */
    public function refLink(Model $ourModel, array $defaults = []): Model
    {
        $theirModel = $this->createTheirModel($defaults);

        $theirModel->addCondition($this->getTheirFieldName($theirModel), $this->referenceOurValue());

        return $theirModel;
    }

    /**
     * Navigate to referenced model.
     */
    public function ref(Model $ourModel, array $defaults = []): Model
    {
        $theirModel = parent::ref($ourModel, $defaults);
        $ourModel = $this->getOurModel($ourModel);

        if ($ourModel->isEntity() && $this->getOurFieldValue($ourModel) !== null) {
            // materialized condition already added in parent/HasOne class
        } else {
            // handle deep traversal using an expression
            $ourFieldExpression = $ourModel->action('field', [$this->getOurField()]);

            $theirModel->getModel(true)
                ->addCondition($this->getTheirFieldName($theirModel), $ourFieldExpression);
        }

        return $theirModel;
    }

    /**
     * Add a title of related entity as expression to our field.
     *
     * $order->hasOne('user_id', 'User')->addTitle();
     *
     * This will add expression 'user' equal to ref('user_id')['name'];
     */
    public function addTitle(array $defaults = []): SqlExpressionField
    {
        $ourModel = $this->getOurModel(null);

        $fieldName = $defaults['field'] ?? preg_replace('~_(' . preg_quote($ourModel->id_field, '~') . '|id)$~', '', $this->link);

        $fieldExpression = $this->_addField($fieldName, true, null, array_merge_recursive([
            'type' => null,
            'ui' => ['editable' => false, 'visible' => true],
        ], $defaults));

        // set ID field as not visible in grid by default
        if (!array_key_exists('visible', $this->getOurField()->ui)) {
            $this->getOurField()->ui['visible'] = false;
        }

        return $fieldExpression;
    }
}
