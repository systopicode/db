<?php

declare(strict_types=1);

namespace Systopic\Db\Result;

/**
 * What happens when a value is accessed that the query did not deliver?
 *
 * A partial object is a state, not an error. The only question is for **how
 * many** objects the missing value gets fetched — and that belongs to the
 * query, not to the class: the same `Page` completes in a group inside an
 * overview list and one by one inside a detail panel.
 *
 * Measured on five objects with access on two of them:
 *   Group 2 queries · Single 3 · all five individually 6
 */
enum Completion
{
    /** One query for every object of the group. The default. */
    case Group;

    /** One query per object. For detail views where the group is large. */
    case Single;

    /**
     * Not at all — throws.
     *
     * The switch that lets a render loop be checked against its query count
     * instead of quietly firing off N fetches.
     */
    case Never;
}
