<?php

namespace App\Enums;

/**
 * Whether an automated resume/application generator may expose a fact.
 *
 * Deliberately independent of Verification: visibility is a confidentiality
 * question, verification is a trust question. Do not derive one from the
 * other.
 */
enum Visibility: string
{
    case Public = 'public';
    case Restricted = 'restricted';
    case Private = 'private';
}
