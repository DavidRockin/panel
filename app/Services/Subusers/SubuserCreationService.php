<?php

namespace Pterodactyl\Services\Subusers;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Services\Users\UserCreationService;
use Pterodactyl\Repositories\Eloquent\SubuserRepository;
use Pterodactyl\Contracts\Repository\UserRepositoryInterface;
use Pterodactyl\Exceptions\Repository\RecordNotFoundException;
use Pterodactyl\Exceptions\Service\Subuser\UserIsServerOwnerException;
use Pterodactyl\Exceptions\Service\Subuser\ServerSubuserExistsException;

class SubuserCreationService
{
    /**
     * @var \Illuminate\Database\ConnectionInterface
     */
    private $connection;

    /**
     * @var \Pterodactyl\Repositories\Eloquent\SubuserRepository
     */
    private $subuserRepository;

    /**
     * @var \Pterodactyl\Services\Users\UserCreationService
     */
    private $userCreationService;

    /**
     * @var \Pterodactyl\Contracts\Repository\UserRepositoryInterface
     */
    private $userRepository;

    /**
     * SubuserCreationService constructor.
     *
     * @param \Illuminate\Database\ConnectionInterface $connection
     * @param \Pterodactyl\Repositories\Eloquent\SubuserRepository $subuserRepository
     * @param \Pterodactyl\Services\Users\UserCreationService $userCreationService
     * @param \Pterodactyl\Contracts\Repository\UserRepositoryInterface $userRepository
     */
    public function __construct(
        ConnectionInterface $connection,
        SubuserRepository $subuserRepository,
        UserCreationService $userCreationService,
        UserRepositoryInterface $userRepository
    ) {
        $this->connection = $connection;
        $this->subuserRepository = $subuserRepository;
        $this->userRepository = $userRepository;
        $this->userCreationService = $userCreationService;
    }

    /**
     * Creates a new user on the system and assigns them access to the provided server.
     * If the email address already belongs to a user on the system a new user will not
     * be created.
     *
     * @param \Pterodactyl\Models\Server $server
     * @param string $email
     * @param array $permissions
     * @return \Pterodactyl\Models\Subuser
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Service\Subuser\ServerSubuserExistsException
     * @throws \Pterodactyl\Exceptions\Service\Subuser\UserIsServerOwnerException
     * @throws \Throwable
     */
    public function handle(Server $server, string $email, array $permissions): Subuser
    {
        return $this->connection->transaction(function () use ($server, $email, $permissions) {
            try {
                $user = $this->userRepository->findFirstWhere([['email', '=', $email]]);

                if ($server->owner_id === $user->id) {
                    throw new UserIsServerOwnerException(trans('exceptions.subusers.user_is_owner'));
                }

                $subuserCount = $this->subuserRepository->findCountWhere([['user_id', '=', $user->id], ['server_id', '=', $server->id]]);
                if ($subuserCount !== 0) {
                    throw new ServerSubuserExistsException(trans('exceptions.subusers.subuser_exists'));
                }
            } catch (RecordNotFoundException $exception) {
                $user = $this->userCreationService->handle([
                    'email' => $email,
                    'username' => preg_replace('/([^\w\.-]+)/', '', strtok($email, '@')) . str_random(3),
                    'name_first' => 'Server',
                    'name_last' => 'Subuser',
                    'root_admin' => false,
                ]);
            }

            return $this->subuserRepository->create([
                'user_id' => $user->id,
                'server_id' => $server->id,
                'permissions' => array_unique($permissions),
            ]);
        });
    }
}
