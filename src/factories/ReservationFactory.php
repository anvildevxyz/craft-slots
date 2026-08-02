<?php

namespace anvildev\slots\factories;

use anvildev\slots\contracts\ReservationInterface;
use anvildev\slots\contracts\ReservationQueryInterface;
use anvildev\slots\elements\Reservation;

/**
 * Constructs reservations and reservation queries.
 *
 * The indirection is worth keeping with a single implementation: `Reservation`
 * is named in ~40 call sites only through these two interfaces, so where a
 * booking is stored stays a decision made in one file.
 */
class ReservationFactory
{
    private const ALLOWED_CREATE_ATTRIBUTES = ['siteId'];

    public static function create(array $attributes = []): ReservationInterface
    {
        $reservation = new Reservation();

        foreach (array_intersect_key($attributes, array_flip(self::ALLOWED_CREATE_ATTRIBUTES)) as $key => $value) {
            if (property_exists($reservation, $key)) {
                $reservation->$key = $value;
            }
        }

        return $reservation;
    }

    /**
     * Reservations are not localized, so every query spans all sites — without
     * this a query made from a non-primary site returns nothing.
     */
    public static function find(): ReservationQueryInterface
    {
        return Reservation::find()->siteId('*');
    }

    public static function findById(int $id): ?ReservationInterface
    {
        return self::find()->id($id)->one();
    }

    public static function findByToken(string $token): ?ReservationInterface
    {
        return $token === '' ? null : self::find()->confirmationToken($token)->one();
    }
}
