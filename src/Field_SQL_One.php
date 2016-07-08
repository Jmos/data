<?php

// vim:ts=4:sw=4:et:fdm=marker:fdl=0

namespace atk4\data;

class Field_SQL_One extends Field_One
{
    /**
     * Creates expression than sub-selects a field inside related model.
     *
     * Returns Expression in case you want to do something else with it.
     */
    public function addField($field, $their_field)
    {
        return $this->owner->addExpression($field, function ($m) use ($their_field) {
            return $m->refLink($this->link)->action('field', [$their_field]);
        });
    }

    /**
     * Add multiple expressions by calling addField several times.
     */
    public function addFields($fields = [])
    {
        foreach ($fields as $field=>$alias) {
            $this->addField($field, $alias);
        }

        return $this;
    }

    /**
     * Creates model that can be used for generating sub-query acitons.
     */
    public function refLink()
    {
        $m = $this->getModel();
        $m->addCondition(
                $this->their_field ?: ($m->id_field),
                $this->referenceOurValue($m)
            );

        return $m;
    }

    public function addTitleField()
    {
    }
}
