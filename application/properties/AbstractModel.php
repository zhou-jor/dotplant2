<?php

namespace app\properties;

use app\models\Object;
use app\models\ObjectStaticValues;
use app\models\Property;
use Yii;
use yii\base\Model;
use yii\helpers\ArrayHelper;

class AbstractModel extends Model
{
    /**
     * @var $values_by_property_key PropertyValue[]
     * @var $form_name string
     * @var $rules array
     */
    private $values_by_property_key = [];
    private $form_name;
    private $properties_models = [];
    private $rules = [];

    public function setFormName($name)
    {
        $this->form_name = $name;
    }

    public function formName()
    {
        return $this->form_name;
    }

    public function setPropertiesModels($properties_models)
    {
        $this->properties_models = $properties_models;
        foreach ($this->properties_models as $property) {
            $this->values_by_property_key[$property->key] = [];
        }
    }

    public function rules()
    {
        $rules = [];
        foreach ($this->properties_models as $property) {
            $rules[] = $property->key;
        }
        return ArrayHelper::merge(
            [
                [$rules, 'safe'],
            ],
            $this->rules
        );
    }

    public function addRules($rules)
    {
        $this->rules = ArrayHelper::merge($this->rules, $rules);
    }

    public function clearRules()
    {
        $this->rules = [];
    }

    public function attributeLabels()
    {
        $labels = [];
        foreach ($this->properties_models as $property) {
            $labels[$property->key] = $property->name;
        }
        return $labels;
    }

    public function __get($name)
    {
        if (isset($this->values_by_property_key[$name])) {
            return $this->values_by_property_key[$name]->toValue();
        }
        return parent::__get($name);
    }

    public function attributes()
    {
        return array_keys($this->values_by_property_key);
    }

    public function setAttributes($values, $safeOnly = true)
    {
        $this->values_by_property_key = $values;
    }

    public function setAttrubutesValues($values)
    {
        foreach ($this->values_by_property_key as $key => $value) {
            if (isset($values[$this->form_name][$key])) {
                if (is_array($values[$this->form_name][$key])) {
                    $this->values_by_property_key[$key]->values = [];
                    foreach ($this->values_by_property_key[$key]->values as $val) {
                        $this->values_by_property_key[$key]->values[] = ['value' => $val];
                    }
                } else {
                    $this->values_by_property_key[$key]->values = [['value' => $values[$this->form_name][$key]]];
                }
            }
        }
    }

    public function updateValues($new_values, $object_id, $object_model_id)
    {
        $column_type_updates = ['object_model_id' => $object_model_id];
        $osv_psv_ids = [];

        $new_eav_values = [];
        $eav_ids_to_delete = [];
        
        foreach ($new_values as $key => $values) {
            $property = Property::findById($values->property_id);
            if ($property->captcha == 1) {
                continue;
            }

            if (!isset($this->values_by_property_key[$key])) {
                // нужно добавить
                if ($property->is_column_type_stored) {
                    $column_type_updates[$key] = (string) $values;
                } elseif ($property->has_static_values) {
                    foreach ($values->values as $val) {
                        $osv_psv_ids[] = $val['value'];
                    }
                } elseif ($property->is_eav) {
                    $new_eav_values[$key] = $values;
                }
            } else {
                if ($property->is_column_type_stored) {
                    $column_type_updates[$key] = (string) $values;
                } elseif ($property->has_static_values) {
                    foreach ($values->values as $val) {
                        $osv_psv_ids[] = $val['value'];
                    }
                } elseif ($property->is_eav) {
                    // добавим новые
                    $new_property_value = new PropertyValue([], $property->id, $object_id, $object_model_id);
                    foreach ($values->values as $val) {
                        $exist_in_old = false;
                        foreach ($this->values_by_property_key[$key]->values as $old_val) {
                            if ($old_val['value'] == $val['value']) {
                                $exist_in_old = true;
                                break;
                            }
                        }
                        if ($exist_in_old == false) {
                            $new_eav_values[] = [
                                $object_model_id,
                                $key,
                                $val['value'],
                                0,
                            ];
                        }
                    }
                    // теперь добавим на удаление
                    foreach ($this->values_by_property_key[$key]->values as $old_val) {
                        $exist_in_new = false;
                        foreach ($values->values as $new_val) {
                            if ($old_val['value'] == $new_val['value']) {
                                $exist_in_new = true;
                                break;
                            }
                        }
                        if ($exist_in_new == false) {
                            $eav_ids_to_delete[] =  $old_val['eav_id'];
                        }
                    }
                }
            }
        }
        $osv_psv_ids_to_delete = [];
        foreach ($this->values_by_property_key as $key => $values) {
            $property = Property::findById($values->property_id);
            if ($property->has_static_values) {
                foreach ($values->values as $val) {
                    if (in_array($val['psv_id'], $osv_psv_ids) === false) {
                        // в новых значениях нет
                        $osv_psv_ids_to_delete[] = $val['psv_id'];
                    } else {
                        // удалим, чтобы заново не добавлять
                        unset(
                            $osv_psv_ids[
                                array_search(
                                    $val['psv_id'],
                                    $osv_psv_ids
                                )
                            ]
                        );
                    }
                }
            }
        }
        if (count($osv_psv_ids_to_delete) > 0) {
            ObjectStaticValues::deleteAll(
                [
                    'and',
                    '`object_id` = :objectId',
                    [
                        'and',
                        '`object_model_id` = :objectModelId',
                        [
                            'in',
                            '`property_static_value_id`',
                            $osv_psv_ids_to_delete
                        ]
                    ]
                ],
                [
                    ':objectId' => $object_id,
                    ':objectModelId' => $object_model_id,
                ]
            );
        }
        if (count($osv_psv_ids) > 0) {
            $rows = [];
            foreach ($osv_psv_ids as $psv_id) {
                // 0 - Not Selected Field. Такие значения в базу не сохраняем
                if ($psv_id == 0) {
                    continue;
                }
                $rows[] = [
                    $object_id, $object_model_id, $psv_id,
                ];
            }
            if (!empty($rows)) {
                Yii::$app->db->createCommand()
                    ->batchInsert(
                        ObjectStaticValues::tableName(),
                        ['object_id', 'object_model_id', 'property_static_value_id'],
                        $rows
                    )->execute();
            }
        }
        Yii::$app->cache->delete("PSV:".$object_id.":".$object_model_id);
        if (count($column_type_updates) > 1) {
            $table_name = Object::findById($object_id)->column_properties_table_name;
            
            Yii::$app->db->createCommand()
                ->delete($table_name, ['object_model_id'=>$object_model_id])
                ->execute();

            Yii::$app->db->createCommand()
                ->insert($table_name, $column_type_updates)
                ->execute();
        }
        if (count($new_eav_values) > 0) {
            $table_name = Object::findById($object_id)->eav_table_name;

            Yii::$app->db->createCommand()
                ->batchInsert($table_name, ['object_model_id', 'key', 'value', 'sort_order'], $new_eav_values)
                ->execute();
        }
        if (count($eav_ids_to_delete) > 0) {
            $table_name = Object::findById($object_id)->eav_table_name;
            
            Yii::$app->db->createCommand()
                ->delete($table_name, ['in', 'id', $eav_ids_to_delete])
                ->execute();
        }
        Yii::$app->cache->delete("TIR:".$object_model_id);
        $this->values_by_property_key = $new_values;
    }
}