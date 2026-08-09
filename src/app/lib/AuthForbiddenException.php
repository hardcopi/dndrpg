<?php
/**
 * Signed in, but not allowed. 403 — signing in again will not help.
 *
 * Kept distinct from AuthRequiredException because the two want opposite
 * responses: one sends you to the login form, the other must not, or an
 * ordinary player who wanders onto an admin route is bounced to a login they
 * have already completed and told to try harder.
 *
 * In its own file for the autoloading reason set out in AuthRequiredException.
 */

declare(strict_types=1);

class AuthForbiddenException extends RuntimeException
{
}
