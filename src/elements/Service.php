<?php

namespace anvildev\slots\elements;

use anvildev\slots\elements\db\ServiceQuery;
use anvildev\slots\elements\traits\HasWeeklySchedule;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\records\ServiceRecord;
use anvildev\slots\traits\HasElementPermissions;
use anvildev\slots\traits\HasEnabledStatus;
use anvildev\slots\traits\HasPropagation;
use anvildev\slots\validators\RefundTiersValidator;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\SetStatus;
use craft\elements\User;
use craft\helpers\Html;

class Service extends Element
{
    use HasEnabledStatus;
    use HasElementPermissions;
    use HasPropagation;
    use HasWeeklySchedule;

    public ?string $description = null;
    public ?int $duration = null;
    public ?int $bufferBefore = null;
    public ?int $bufferAfter = null;
    public ?float $price = null;
    public ?int $minTimeBeforeBooking = null;
    public ?int $timeSlotLength = null;
    public array|string|null $availabilitySchedule = null;
    public bool $customerLimitEnabled = false;
    public ?int $customerLimitCount = null;
    public ?string $customerLimitPeriod = null;
    public ?string $customerLimitPeriodType = null;
    public bool $allowCancellation = true;
    public ?int $cancellationPolicyHours = null;
    public bool $allowRefund = false;
    public array|string|null $refundTiers = null;
    public ?int $taxCategoryId = null;
    public ?string $deletedAt = null;

    public function getDurationLabel(): string
    {
        if ($this->duration === null) {
            return '';
        }

        $unit = \Craft::t('slots', 'labels.min');

        return $this->duration . ' ' . $unit;
    }

    public function softDelete(): void
    {
        $this->deletedAt = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    public function isSoftDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function beforeDelete(): bool
    {
        if (!parent::beforeDelete()) {
            return false;
        }

        $activeCount = $this->getActiveReservationCount();

        if ($activeCount > 0) {
            $this->addError('id', Craft::t('slots', 'error.cannotDeleteServiceWithReservations', ['count' => $activeCount]));
            return false;
        }

        return true;
    }

    public function canDelete(User $user): bool
    {
        return ($user->admin || $user->can($this->permissionKey()))
            && $this->getActiveReservationCount() === 0;
    }

    public static function displayName(): string
    {
        return Craft::t('slots', 'labels.service');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('slots', 'labels.services');
    }

    public static function refHandle(): ?string
    {
        return 'service';
    }



    public static function createCondition(): \craft\elements\conditions\ElementConditionInterface
    {
        return \Craft::createObject(conditions\ServiceCondition::class, [static::class]);
    }

    public static function hasTitles(): bool
    {
        return true;
    }
    public static function isLocalized(): bool
    {
        return true;
    }
    /** @return ServiceQuery */
    public static function find(): ServiceQuery
    {
        return new ServiceQuery(static::class);
    }

    protected static function defineActions(?string $source = null): array
    {
        return [SetStatus::class, Delete::class];
    }

    protected static function defineExporters(string $source): array
    {
        $exporters = parent::defineExporters($source);
        $exporters[] = exporters\ServiceCatalogCsvExporter::class;
        return $exporters;
    }

    protected function decodeAvailabilitySchedule(): void
    {
        if (is_string($this->availabilitySchedule) && $this->availabilitySchedule !== '') {
            $decoded = json_decode($this->availabilitySchedule, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->availabilitySchedule = $decoded;
            }
        }
    }

    protected static function defineSources(string $context): array
    {
        return [['key' => '*', 'label' => Craft::t('slots', 'element.allServices')]];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('app', 'Title')],
            'duration' => ['label' => Craft::t('slots', 'labels.duration')],
            'price' => ['label' => Craft::t('slots', 'labels.price')],
            'timeSlotLength' => ['label' => Craft::t('slots', 'labels.timeSlotLength')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['title', 'duration', 'price', 'timeSlotLength'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['description'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('app', 'Title'),
                'orderBy' => 'elements_sites.title',
                'attribute' => 'title',
            ],
            [
                'label' => Craft::t('slots', 'labels.duration'),
                'orderBy' => 'slots_services.duration',
                'attribute' => 'duration',
            ],
            [
                'label' => Craft::t('slots', 'labels.price'),
                'orderBy' => 'slots_services.price',
                'attribute' => 'price',
            ],
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
            ],
        ];
    }


    protected function attributeHtml(string $attribute): string
    {
        $dash = Html::tag('span', '–', ['class' => 'light']);
        return match ($attribute) {
            'duration' => $this->duration !== null ? Html::encode($this->getDurationLabel()) : $dash,
            'price' => $this->price !== null ? Craft::$app->formatter->asCurrency($this->price) : $dash,
            default => parent::attributeHtml($attribute),
        };
    }

    protected function cpEditUrl(): ?string
    {
        return 'slots/services/' . $this->id;
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['description'], 'string'],
            [['duration'], 'required'],
            [['duration'], 'integer', 'min' => 1],
            [['bufferBefore', 'bufferAfter'], 'integer', 'min' => 0],
            [['price'], 'number', 'min' => 0],
            [['minTimeBeforeBooking'], 'integer', 'min' => 0],
            [['timeSlotLength'], 'integer', 'min' => 5],
            [['customerLimitEnabled'], 'boolean'],
            [['customerLimitCount'], 'integer', 'min' => 1],
            [['customerLimitPeriod'], 'string', 'max' => 20],
            [['customerLimitPeriodType'], 'in', 'range' => ['fixed', 'rolling']],
            [['allowCancellation'], 'boolean'],
            [['cancellationPolicyHours'], 'integer', 'min' => 0],
            [['allowRefund'], 'boolean'],
            [['refundTiers'], RefundTiersValidator::class],
            [['taxCategoryId'], 'integer', 'skipOnEmpty' => true],
        ]);
    }

    private function getActiveReservationCount(): int
    {
        return (int)ReservationRecord::find()
            ->where(['serviceId' => $this->id])
            ->andWhere(['not', ['status' => ReservationRecord::STATUS_CANCELLED]])
            ->count();
    }

    public function canDuplicate(User $user): bool
    {
        return true;
    }

    public function afterPopulate(): void
    {
        parent::afterPopulate();
        $this->decodeAvailabilitySchedule();
        if (is_string($this->refundTiers)) {
            $this->refundTiers = json_decode($this->refundTiers, true);
        }
    }

    protected function permissionKey(): string
    {
        return 'slots-manageServices';
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = (!$isNew ? ServiceRecord::findOne($this->id) : null) ?? new ServiceRecord();
            if (!$record->id) {
                $record->id = (int)$this->id;
            }

            $record->propagationMethod = $this->propagationMethod->value;
            $record->description = $this->description;
            $record->duration = $this->duration;
            $record->bufferBefore = $this->bufferBefore;
            $record->bufferAfter = $this->bufferAfter;
            $record->price = $this->price;
            $record->allowCancellation = $this->allowCancellation;
            $record->cancellationPolicyHours = $this->cancellationPolicyHours;
            $record->allowRefund = $this->allowRefund;
            $record->refundTiers = $this->refundTiers ? json_encode($this->refundTiers) : null;
            $record->minTimeBeforeBooking = $this->minTimeBeforeBooking;
            $record->timeSlotLength = $this->timeSlotLength;
            $record->customerLimitEnabled = $this->customerLimitEnabled;
            $record->customerLimitCount = $this->customerLimitCount;
            $record->customerLimitPeriod = $this->customerLimitPeriod;
            $record->customerLimitPeriodType = $this->customerLimitPeriodType;
            $record->taxCategoryId = $this->taxCategoryId;
            $record->deletedAt = $this->deletedAt;
            $record->availabilitySchedule = match (true) {
                is_array($this->availabilitySchedule) => $this->availabilitySchedule,
                is_string($this->availabilitySchedule) => json_decode($this->availabilitySchedule, true),
                default => null,
            };
            $record->save(false);
        }
        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        ServiceRecord::findOne($this->id)?->delete();
        parent::afterDelete();
    }

    public function hasAvailabilitySchedule(): bool
    {
        return $this->id && !empty(
            \anvildev\slots\Slots::getInstance()->getScheduleAssignment()->getSchedulesForService($this->id)
        );
    }

    public function getScheduleData(): ?array
    {
        $s = is_string($this->availabilitySchedule) ? json_decode($this->availabilitySchedule, true) : $this->availabilitySchedule;
        return is_array($s) ? $s : null;
    }

    public function extraFields(): array
    {
        return [
            'schedules' => 'getSchedules',
            'hasAvailabilitySchedule' => 'hasAvailabilitySchedule',
        ];
    }

    /** @return Schedule[] */
    public function getSchedules(): array
    {
        return $this->id ? \anvildev\slots\Slots::getInstance()->scheduleAssignment->getSchedulesForService($this->id) : [];
    }
}
