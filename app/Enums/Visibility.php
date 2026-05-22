<?php

namespace App\Enums;

/**
 * Issue visibility values.
 *
 * @see SPEC §4.2 / BR-01
 */
enum Visibility: string
{
    case Private = 'private';
    case Public = 'public';
}
