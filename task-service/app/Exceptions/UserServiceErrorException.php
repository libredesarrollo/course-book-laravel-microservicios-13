<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El user-service respondió, pero con un error (500, 502, cuerpo inválido...).
 *
 * Al revés que UserServiceUnavailableException: el servicio está vivo pero
 * no pudo cumplir el contrato.
 */
class UserServiceErrorException extends RuntimeException {}
