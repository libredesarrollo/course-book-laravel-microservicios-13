<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El user-service no respondió a tiempo o rechazó la conexión.
 *
 * Representa una caída de infraestructura del otro microservicio, no un
 * problema con los datos enviados.
 */
class UserServiceUnavailableException extends RuntimeException {}
