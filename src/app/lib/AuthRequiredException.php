<?php
/**
 * Nobody is signed in. The caller should be sent to the login form. 401.
 *
 * In its own file rather than beside Auth, and this is not tidiness. The
 * autoloader maps one class to one file, so `catch (AuthRequiredException $e)`
 * in a file that had not already touched Auth would ask the autoloader for a
 * class it could not find — and PHP does not raise on a failed autoload while
 * matching a catch type, it simply decides the type does not match. The handler
 * would be skipped and the exception would escape as an unhandled 500. Silent,
 * and only in the files that most needed the catch to work.
 */

declare(strict_types=1);

class AuthRequiredException extends RuntimeException
{
}
