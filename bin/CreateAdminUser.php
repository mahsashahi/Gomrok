<?php

declare(strict_types=1);

/**
 * Create an admin panel user (bootstrap tool — there is no admin UI action
 * for the very first admin account, since /admin/admin-users itself requires
 * already being signed in).
 *
 *   php bin/CreateAdminUser.php --name="Jane Doe" --email=jane@example.com \
 *       --password=<a real password, min 10 chars> --role=admin
 *
 * The password is typed by the caller, not generated (CreateAdminUserCommand's
 * own doc: a password authenticates a person, not a machine).
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Admin\Application\CreateAdminUser\CreateAdminUserCommand;
use Gomrok\Modules\Admin\Application\CreateAdminUser\CreateAdminUserHandler;
use Gomrok\Modules\Admin\Application\CreateAdminUser\CreateAdminUserResult;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['name:', 'email:', 'password:', 'role:']);
if ($opts === false || !isset($opts['name'], $opts['email'], $opts['password'], $opts['role'])) {
    fwrite(STDERR, "usage: php bin/CreateAdminUser.php --name=<name> --email=<email> --password=<password> --role=<admin|support_agent>\n");
    exit(2);
}

$name = is_string($opts['name']) ? $opts['name'] : '';
$email = is_string($opts['email']) ? $opts['email'] : '';
$password = is_string($opts['password']) ? $opts['password'] : '';
$role = is_string($opts['role']) ? $opts['role'] : '';

$handler = ContainerFactory::create()->get(CreateAdminUserHandler::class);
assert($handler instanceof CreateAdminUserHandler);

$result = $handler->handle(new CreateAdminUserCommand($name, $email, $password, $role));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof CreateAdminUserResult);

fwrite(STDOUT, "admin user created\n");
fwrite(STDOUT, "  id:    {$payload->adminUserId}\n");
fwrite(STDOUT, "  email: {$email}\n");
fwrite(STDOUT, "  role:  {$role}\n");

exit(0);
