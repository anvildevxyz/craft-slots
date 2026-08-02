<?php

namespace anvildev\slots\migrations;

use anvildev\slots\elements\Reservation;
use craft\db\Migration;
use craft\helpers\StringHelper;

/**
 * Gives every reservation a row in the `elements` table so bookings become
 * first-class Craft elements — queryable from Twig, listable in an element
 * index, and usable with element actions and exporters.
 *
 * Reservations were plain ActiveRecord rows with their own auto-increment key,
 * so their ids have to be remapped onto element ids. That is done in two passes
 * to guarantee no id ever collides with one still in use:
 *
 *  1. every reservation id is shifted above the highest id the new element rows
 *     can possibly be assigned
 *  2. each row is then lowered onto its real element id, which is now certain
 *     to be free
 *
 * `slots_payments.reservationId` is the only foreign key pointing at
 * reservations, so it is dropped for the duration and rebuilt afterwards.
 */
class m260802_000001_reservations_as_elements extends Migration
{
    public function safeUp(): bool
    {
        $reservations = (new \craft\db\Query())
            ->select(['id', 'siteId', 'dateCreated', 'dateUpdated', 'uid', 'status'])
            ->from('{{%slots_reservations}}')
            ->orderBy(['id' => SORT_ASC])
            ->all();

        if (!$reservations) {
            $this->addElementForeignKey();
            return true;
        }

        $paymentsFk = $this->foreignKeyName('{{%slots_payments}}', 'reservationId');
        if ($paymentsFk !== null) {
            $this->dropForeignKey($paymentsFk, '{{%slots_payments}}');
        }

        // Pass 1 — park every id above anything the auto-increment can hand out.
        $maxElementId = (int)(new \craft\db\Query())->from('{{%elements}}')->max('[[id]]');
        $maxReservationId = (int)(new \craft\db\Query())->from('{{%slots_reservations}}')->max('[[id]]');
        $offset = $maxElementId + $maxReservationId + count($reservations) + 1;

        $this->execute("UPDATE {{%slots_reservations}} SET [[id]] = [[id]] + {$offset}");
        $this->execute("UPDATE {{%slots_payments}} SET [[reservationId]] = [[reservationId]] + {$offset}");

        // Pass 2 — one element row per reservation, then lower the id onto it.
        $primarySiteId = \Craft::$app->getSites()->getPrimarySite()->id;

        foreach ($reservations as $reservation) {
            $parkedId = (int)$reservation['id'] + $offset;
            $siteId = (int)($reservation['siteId'] ?: $primarySiteId);

            $this->insert('{{%elements}}', [
                'type' => Reservation::class,
                'enabled' => true,
                'archived' => false,
                'dateCreated' => $reservation['dateCreated'],
                'dateUpdated' => $reservation['dateUpdated'],
                'uid' => $reservation['uid'] ?: StringHelper::UUID(),
            ]);
            $elementId = (int)$this->db->getLastInsertID('{{%elements}}');

            $this->insert('{{%elements_sites}}', [
                'elementId' => $elementId,
                'siteId' => $siteId,
                'enabled' => true,
                'dateCreated' => $reservation['dateCreated'],
                'dateUpdated' => $reservation['dateUpdated'],
                'uid' => StringHelper::UUID(),
            ]);

            $this->update('{{%slots_reservations}}', ['id' => $elementId], ['id' => $parkedId]);
            $this->update('{{%slots_payments}}', ['reservationId' => $elementId], ['reservationId' => $parkedId]);
        }

        $this->addElementForeignKey();

        if ($paymentsFk !== null) {
            $this->addForeignKey(null, '{{%slots_payments}}', 'reservationId', '{{%slots_reservations}}', 'id', 'CASCADE', null);
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260802_000001_reservations_as_elements cannot be reverted: the original reservation ids are not recoverable.\n";
        return false;
    }

    private function addElementForeignKey(): void
    {
        if ($this->foreignKeyName('{{%slots_reservations}}', 'id') === null) {
            $this->addForeignKey(null, '{{%slots_reservations}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
        }
    }

    /**
     * The generated constraint name, or null when the column carries no foreign key.
     */
    private function foreignKeyName(string $table, string $column): ?string
    {
        $rawTable = $this->db->getSchema()->getRawTableName($table);
        $schema = $this->db->getTableSchema($rawTable);

        if ($schema === null) {
            return null;
        }

        foreach ($schema->foreignKeys as $name => $definition) {
            if (array_key_exists($column, array_slice($definition, 1))) {
                return (string)$name;
            }
        }

        return null;
    }
}
