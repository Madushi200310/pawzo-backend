<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Match Score
    |--------------------------------------------------------------------------
    |
    | Only suggestions scoring at least this value will be saved.
    | This is a ranking score, not a probability percentage.
    |
    */

    'minimum_score' => 55,

    /*
    |--------------------------------------------------------------------------
    | Maximum Distance
    |--------------------------------------------------------------------------
    |
    | Maximum distance in kilometres between the lost location
    | and the found location.
    |
    */

    'maximum_distance_km' => 50,

    /*
    |--------------------------------------------------------------------------
    | Maximum Time Gap
    |--------------------------------------------------------------------------
    |
    | The pet must have been found at or after the lost time,
    | within this number of days.
    |
    */

    'maximum_days' => 90,

];