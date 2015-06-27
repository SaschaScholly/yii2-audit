<?php
namespace bedezign\yii2\audit;

use Yii;
use yii\base\Exception;
use yii\db\ActiveRecord;

use bedezign\yii2\audit\models\AuditTrail;
use yii\web\Application;

/**
 * Class AuditTrailBehavior
 * @package bedezign\yii2\audit
 *
 * @property \yii\db\ActiveRecord $owner
 */
class AuditTrailBehavior extends \yii\base\Behavior
{

    /**
     * Array with fields to save
     * You don't need to configure both `allowed` and `ignored`
     * @var array
     */
    public $allowed = [];

    /**
     * Array with fields to ignore
     * You don't need to configure both `allowed` and `ignored`
     * @var array
     */
    public $ignored = [];

    /**
     * Array with classes to ignore
     * @var array
     */
    public $ignoredClasses = [];

    /**
     * Skip fields where bouth old and new values are NULL
     * @var boolean
     */
    public $skipNulls = true;

    /**
     * Is the behavior is active or not
     * @var boolean
     */
    public $active = true;

    /**
     * Model attribute to use to get the user_id from an attribute in the owner model
     * Set to null to get the user_id from `Yii::$app->user->id`
     * @var string|null
     */
    public $userAttribute;

    /**
     * Date format to use in stamp - set to "Y-m-d H:i:s" for datetime or "U" for timestamp
     * @var string
     */
    public $dateFormat = 'Y-m-d H:i:s';

    /**
     * @var array
     */
    private $_oldAttributes = [];

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }

    /**
     *
     */
    public function afterFind()
    {
        $this->setOldAttributes($this->owner->getAttributes());
    }

    /**
     *
     */
    public function afterInsert()
    {
        $this->audit('CREATE');
    }

    /**
     *
     */
    public function afterUpdate()
    {
        $this->audit('UPDATE');
    }

    /**
     *
     */
    public function afterDelete()
    {
        $this->audit('DELETE');
    }

    /**
     * @param $action
     * @throws \yii\db\Exception
     */
    public function audit($action)
    {
        // If this is a delete then just write one row and get out of here
        if ($action == 'DELETE') {
            $this->saveAuditTrailDelete();
            return;
        }
        // Get the new and old attributes
        $newAttributes = $this->owner->getAttributes();
        $oldAttributes = $this->getOldAttributes();
        // Lets check if the whole class should be ignored
        if (sizeof($this->ignoredClasses) > 0) {
            if (array_search(get_class($this->owner), $this->ignoredClasses) !== false) {
                return;
            }
        }
        // Lets unset fields which are not allowed
        if (sizeof($this->allowed) > 0) {
            foreach ($newAttributes as $f => $v) {
                if (array_search($f, $this->allowed) === false) {
                    unset($newAttributes[$f]);
                }
            }
            foreach ($oldAttributes as $f => $v) {
                if (array_search($f, $this->allowed) === false) {
                    unset($oldAttributes[$f]);
                }
            }
        }
        // Lets unset fields which are ignored
        if (sizeof($this->ignored) > 0) {
            foreach ($newAttributes as $f => $v) {
                if (array_search($f, $this->ignored) !== false)
                    unset($newAttributes[$f]);
            }
            foreach ($oldAttributes as $f => $v) {
                if (array_search($f, $this->ignored) !== false)
                    unset($oldAttributes[$f]);
            }
        }
        // If no difference then get out of here
        if (count(array_diff_assoc($newAttributes, $oldAttributes)) <= 0) {
            return;
        }
        // Now lets actually write the attributes
        $this->auditAttributes($action, $newAttributes, $oldAttributes);
        // Reset old attributes to handle the case with the same model instance updated multiple times
        $this->setOldAttributes($this->owner->getAttributes());
    }

    /**
     * @param       $action
     * @param       $newAttributes
     * @param array $oldAttributes
     * @throws \yii\db\Exception
     */
    public function auditAttributes($action, $newAttributes, $oldAttributes = [])
    {
        if (!$this->active) {
            return;
        }

        $entry_id = $this->getAuditEntryId();
        $user_id = $this->getUserId();
        $model = $this->owner->className();
        $model_id = $this->getNormalizedPk();
        $created = date($this->dateFormat);

        $this->saveAuditTrail($action, $newAttributes, $oldAttributes, $entry_id, $user_id, $model, $model_id, $created);
    }

    /**
     * @param $action
     * @param $newAttributes
     * @param $oldAttributes
     * @param $entry_id
     * @param $user_id
     * @param $model
     * @param $model_id
     * @param $created
     * @throws \yii\db\Exception
     */
    protected function saveAuditTrail($action, $newAttributes, $oldAttributes, $entry_id, $user_id, $model, $model_id, $created)
    {
        // Build a list of fields to log
        $rows = array();
        foreach ($newAttributes as $field => $new) {
            $old = isset($oldAttributes[$field]) ? $oldAttributes[$field] : '';
            // If we are skipping nulls then lets see if both sides are null
            if ($this->skipNulls && empty($old) && empty($new)) {
                continue;
            }
            // If they are not the same lets write an audit log
            if ($new != $old) {
                $rows[] = [$entry_id, $user_id, $old, $new, $action, $model, $model_id, $field, $created];
            }
        }
        // Record the field changes with a batch insert
        if (!empty($rows)) {
            $columns = ['entry_id', 'user_id', 'old_value', 'new_value', 'action', 'model', 'model_id', 'field', 'created'];
            Yii::$app->db->createCommand()->batchInsert(AuditTrail::tableName(), $columns, $rows)->execute();
        }
    }

    /**
     * @throws \yii\db\Exception
     */
    protected function saveAuditTrailDelete()
    {
        if (!$this->active) {
            return;
        }
        Yii::$app->db->createCommand()->insert(AuditTrail::tableName(), [
            'action' => 'DELETE',
            'entry_id' => $this->getAuditEntryId(),
            'user_id' => $this->getUserId(),
            'model' => $this->owner->className(),
            'model_id' => $this->getNormalizedPk(),
            'created' => date($this->dateFormat),
        ])->execute();
    }

    /**
     * @return array
     */
    public function getOldAttributes()
    {
        return $this->_oldAttributes;
    }

    /**
     * @param $value
     */
    public function setOldAttributes($value)
    {
        $this->_oldAttributes = $value;
    }

    /**
     * @return string
     */
    protected function getNormalizedPk()
    {
        $pk = $this->owner->getPrimaryKey();
        return is_array($pk) ? json_encode($pk) : $pk;
    }

    /**
     * @return int|null|string
     */
    protected function getUserId()
    {
        if (isset($this->userAttribute)) {
            $data = $this->owner->getAttributes();
            return isset($data[$this->userAttribute]) ? $data[$this->userAttribute] : null;
        }
        if (Yii::$app instanceof Application && Yii::$app->user) {
            return Yii::$app->user->id;
        }
        return null;
    }

    /**
     * @return models\AuditEntry|null|static
     */
    protected function getAuditEntryId()
    {
        return Audit::getInstance()->getEntry(true)->id;
    }

}
