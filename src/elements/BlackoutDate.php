<?php

namespace anvildev\slots\elements;

use anvildev\slots\elements\db\BlackoutDateQuery;
use anvildev\slots\records\BlackoutDateRecord;
use anvildev\slots\traits\HasElementPermissions;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\db\EagerLoadPlan;
use craft\elements\User;
use craft\helpers\Html;

class BlackoutDate extends Element
{
    use HasElementPermissions;

    public string $startDate = '';
    public string $endDate = '';
    /** @var int[] Write-only for saving; use getLocations() to read from DB */
    public array $locationIds = [];
    /** @var int[] Write-only for saving; use getEmployees() to read from DB */
    public array $employeeIds = [];
    public bool $isActive = true;

    private ?array $_locations = null;
    private ?array $_employees = null;

    public static function displayName(): string
    {
        return Craft::t('slots', 'element.blackoutDate');
    }
    public static function pluralDisplayName(): string
    {
        return Craft::t('slots', 'element.blackoutDates');
    }
    public static function refHandle(): ?string
    {
        return 'blackoutDate';
    }
    public static function hasTitles(): bool
    {
        return true;
    }
    public static function hasStatuses(): bool
    {
        return true;
    }
    public static function statuses(): array
    {
        return ['active' => 'green', 'inactive' => null];
    }
    public function getStatus(): ?string
    {
        return $this->isActive ? 'active' : 'inactive';
    }

    protected static function defineActions(?string $source = null): array
    {
        return [Delete::class];
    }

    /** @return BlackoutDateQuery */
    public static function find(): BlackoutDateQuery
    {
        return new BlackoutDateQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('slots', 'element.allBlackoutDates'),
                'defaultSort' => ['startDate', 'desc'],
                'type' => 'native',
            ],
            [
                'heading' => Craft::t('slots', 'labels.status'),
            ],
            [
                'key' => 'active',
                'label' => Craft::t('slots', 'status.active'),
                'criteria' => ['isActive' => true],
                'defaultSort' => ['startDate', 'desc'],
                'type' => 'native',
            ],
            [
                'key' => 'inactive',
                'label' => Craft::t('slots', 'status.inactive'),
                'criteria' => ['isActive' => false],
                'defaultSort' => ['startDate', 'desc'],
                'type' => 'native',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'id' => ['label' => 'ID'],
            'title' => ['label' => Craft::t('slots', 'labels.name')],
            'dateRange' => ['label' => Craft::t('slots', 'labels.dateRange')],
            'location' => ['label' => Craft::t('slots', 'labels.location')],
            'employee' => ['label' => Craft::t('slots', 'labels.employee')],
            'duration' => ['label' => Craft::t('slots', 'labels.duration')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['title', 'dateRange', 'location', 'employee'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['startDate', 'endDate'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('slots', 'labels.name'),
                'orderBy' => 'elements_sites.title',
                'attribute' => 'title',
            ],
            [
                'label' => Craft::t('slots', 'labels.startDate'),
                'orderBy' => 'slots_blackout_dates.startDate',
                'attribute' => 'startDate',
            ],
            [
                'label' => Craft::t('slots', 'labels.dateCreated'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
            ],
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        $url = $this->getCpEditUrl();
        return match ($attribute) {
            'id' => $url ? Html::a('#' . $this->id, $url) : '#' . $this->id,
            'title' => $url
                ? Html::a(Html::encode($this->title ?: Craft::t('slots', 'labels.untitled')), $url)
                : Html::encode($this->title ?: Craft::t('slots', 'labels.untitled')),
            'dateRange' => Html::encode($this->getFormattedDateRange()),
            'location' => ($locs = $this->getLocations())
                ? implode(', ', array_map(fn($loc) => Html::encode($loc->title), $locs))
                : Html::tag('span', Craft::t('slots', 'labels.allLocations'), ['class' => 'light']),
            'employee' => ($emps = $this->getEmployees())
                ? implode(', ', array_map(fn($emp) => Html::encode($emp->title), $emps))
                : Html::tag('span', Craft::t('slots', 'labels.allEmployees'), ['class' => 'light']),
            'duration' => Html::encode(($d = $this->getDurationDays()) . ' ' . ($d == 1 ? Craft::t('slots', 'labels.day') : Craft::t('slots', 'labels.days'))),
            default => parent::attributeHtml($attribute),
        };
    }

    protected function cpEditUrl(): ?string
    {
        return 'slots/blackout-dates/' . $this->id;
    }
    protected function permissionKey(): string
    {
        return 'slots-manageBookings';
    }
    public function canDuplicate(User $user): bool
    {
        return $user->admin || $user->can('slots-manageBookings');
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['startDate', 'endDate'], 'required'],
            [['startDate', 'endDate'], 'date', 'format' => 'php:Y-m-d'],
            [['endDate'], 'validateEndDateAfterStart'],
            [['isActive'], 'boolean'],
        ]);
    }

    public function validateEndDateAfterStart(string $attribute): void
    {
        if ($this->endDate && $this->startDate && $this->endDate < $this->startDate) {
            $this->addError($attribute, Craft::t('slots', 'validation.endDateAfterStart'));
        }
    }

    public function afterSave(bool $isNew): void
    {
        $record = $isNew ? new BlackoutDateRecord() : (BlackoutDateRecord::findOne($this->id)
            ?? throw new \Exception('Invalid blackout date ID: ' . $this->id));
        if ($isNew) {
            $record->id = (int)$this->id;
        }

        $record->title = $this->title ?? '';
        $record->startDate = $this->startDate;
        $record->endDate = $this->endDate;
        $record->isActive = $this->isActive;

        $db = Craft::$app->db;
        $now = date('Y-m-d H:i:s');

        $transaction = $db->beginTransaction();
        try {
            $record->save(false);

            // Save location relationships
            $db->createCommand()->delete('{{%slots_blackout_dates_locations}}', ['blackoutDateId' => $this->id])->execute();
            if (!empty($this->locationIds)) {
                $db->createCommand()->batchInsert('{{%slots_blackout_dates_locations}}',
                    ['blackoutDateId', 'locationId', 'dateCreated', 'dateUpdated', 'uid'],
                    array_map(fn($locId) => [$this->id, $locId, $now, $now, \craft\helpers\StringHelper::UUID()], $this->locationIds)
                )->execute();
            }

            // Save employee relationships
            $db->createCommand()->delete('{{%slots_blackout_dates_employees}}', ['blackoutDateId' => $this->id])->execute();
            if (!empty($this->employeeIds)) {
                $db->createCommand()->batchInsert('{{%slots_blackout_dates_employees}}',
                    ['blackoutDateId', 'employeeId', 'dateCreated', 'dateUpdated', 'uid'],
                    array_map(fn($empId) => [$this->id, $empId, $now, $now, \craft\helpers\StringHelper::UUID()], $this->employeeIds)
                )->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        BlackoutDateRecord::findOne($this->id)?->delete();
        parent::afterDelete();
    }

    public function getFormattedDateRange(): string
    {
        $start = \DateTime::createFromFormat('Y-m-d', $this->startDate);
        $end = \DateTime::createFromFormat('Y-m-d', $this->endDate);
        if (!$start || !$end) {
            return $this->startDate . ' - ' . $this->endDate;
        }
        $locale = Craft::$app->language ?: 'en';
        $fmt = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
        return $this->startDate === $this->endDate
            ? $fmt->format($start)
            : $fmt->format($start) . ' - ' . $fmt->format($end);
    }

    public function getDurationDays(): int
    {
        return (new \DateTime($this->startDate))->diff(new \DateTime($this->endDate))->days + 1;
    }

    /** @return Location[] */
    public function getLocations(): array
    {
        if ($this->_locations === null) {
            $ids = $this->id
                ? (new \craft\db\Query())->select(['locationId'])->from('{{%slots_blackout_dates_locations}}')->where(['blackoutDateId' => $this->id])->column()
                : [];
            $this->_locations = $ids ? Location::find()->id($ids)->siteId('*')->all() : [];
        }
        return $this->_locations;
    }

    /** @return Employee[] */
    public function getEmployees(): array
    {
        if ($this->_employees === null) {
            $ids = $this->id
                ? (new \craft\db\Query())->select(['employeeId'])->from('{{%slots_blackout_dates_employees}}')->where(['blackoutDateId' => $this->id])->column()
                : [];
            $this->_employees = $ids ? Employee::find()->id($ids)->siteId('*')->all() : [];
        }
        return $this->_employees;
    }

    /**
     * @inheritdoc
     */
    public static function eagerLoadingMap(array $sourceElements, string $handle): array|null|false
    {
        if ($handle === 'locations' || $handle === 'employees') {
            $allIds = array_filter(array_map(fn($el) => $el->id, $sourceElements));
            if (empty($allIds)) {
                return [
                    'elementType' => $handle === 'locations' ? Location::class : Employee::class,
                    'map' => [],
                ];
            }

            $table = $handle === 'locations'
                ? '{{%slots_blackout_dates_locations}}'
                : '{{%slots_blackout_dates_employees}}';
            $col = $handle === 'locations' ? 'locationId' : 'employeeId';

            $rows = (new \craft\db\Query())
                ->select(['blackoutDateId', $col])
                ->from($table)
                ->where(['blackoutDateId' => $allIds])
                ->all();

            $map = array_map(
                fn($row) => ['source' => $row['blackoutDateId'], 'target' => $row[$col]],
                $rows,
            );

            return [
                'elementType' => $handle === 'locations' ? Location::class : Employee::class,
                'map' => $map,
            ];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }

    /**
     * @inheritdoc
     */
    public function setEagerLoadedElements(string $handle, array $elements, EagerLoadPlan $plan): void
    {
        switch ($handle) {
            case 'locations':
                $this->_locations = $elements;
                break;
            case 'employees':
                $this->_employees = $elements;
                break;
            default:
                parent::setEagerLoadedElements($handle, $elements, $plan);
        }
    }
}
