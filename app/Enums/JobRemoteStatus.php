<?php

namespace App\Enums;

/**
 * Normalized remote/workplace signal across providers with different
 * vocabularies (Lever's workplaceType, Ashby's isRemote+workplaceType,
 * Himalayas' locationRestrictions). Null on the model when a provider
 * gives no usable signal at all — never guessed.
 */
enum JobRemoteStatus: string
{
    case Remote = 'remote';
    case Hybrid = 'hybrid';
    case Onsite = 'onsite';
}
