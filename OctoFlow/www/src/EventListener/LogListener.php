<?php

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Doctrine\ORM\EntityManagerInterface;

final class LogListener
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[AsEventListener]
    public function onControllerEvent(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $payload = json_decode($request->getContent(), true);
        $action = $payload['action'] ?? null;
        // if ($action == 'login') {
        //     return;
        // }
        $changedId = $payload['id'] ?? $request->attributes->get('id');

        // Filtra apenas métodos e ações de interesse
        if (
            !in_array($request->getMethod(), ['POST', 'PATCH', 'DELETE'])
        ) {
            return;
        }

        // Dados do usuário logado
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        $userId = ($user instanceof User) ? $user->getId() : null;

        // Entidade
        $resourceClass = $request->attributes->get('_api_resource_class');
        $entityName = null;
        $value = [];
        if ($resourceClass && class_exists($resourceClass)) {
            $entityName = basename(str_replace('\\', '/', $resourceClass));

            if ($request->getMethod() === 'PATCH' && $changedId) {
                $original = $this->entityManager->find($resourceClass, $changedId);
                if ($original) {
                    $originalData = $this->normalize($original);
                    foreach ($payload as $field => $newVal) {
                        if (array_key_exists($field, $originalData) && $originalData[$field] !== $newVal) {
                            $value[$field] = $newVal;
                        }
                    }
                }
            } elseif (in_array($request->getMethod(), ['POST', 'DELETE'])) {
                $value = $payload;
            }
        }
        if ($request->getPathInfo() == '/auth/login') {
            $action = 'login';
            $entityName = 'Login';
            $changedId = $this->entityManager->getRepository(\App\Entity\User::class)->findOneBy(['email' => $payload['email'] ?? ''])?->getId() ?? 0;
            $value = [$payload['email'] ?? ''];
        }
        if ($request->getPathInfo() == '/auth/refresh') {
            $action = 'refresh';
            $entityName = 'Login';
            $changedId = $userId;
            $value = [];
        }
        if ($request->getPathInfo() == '/new/apikey') {
            $action = 'create';
            $entityName = 'ApiKey';
            $changedId = $userId;
            $value = [];
        }
        $createdAt = null;
        $updatedAt = new \DateTimeImmutable();
        $deletedAt = null;
        if ($action == 'create') {
            $createdAt = $updatedAt;
        }
        if ($request->getMethod() == "DELETE") {
            $action = 'delete';
            $deletedAt = new \DateTimeImmutable();
        }
        // $log = new Log();
        // $log->setUserId($userId)
        //     ->setUserIp($request->getClientIp())
        //     ->setSystem($request->headers->get('User-Agent'))
        //     ->setAction($action)
        //     ->setCreatedAt($createdAt)
        //     ->setUpdatedAt($updatedAt)
        //     ->setDeletedAt($deletedAt)
        //     ->setEntityName($entityName)
        //     ->setPath($request->getPathInfo())
        //     ->setMethod($request->getMethod())
        //     ->setChangedId($changedId)
        //     ->setValue($value, JSON_UNESCAPED_UNICODE);
        // $this->entityManager->persist($log);
        // $this->entityManager->flush();
        // Montar log
        $logData = [
            'user_id'        => $userId,
            'user_ip'        => $request->getClientIp(),
            'system'     => $request->headers->get('User-Agent'),
            'action'         => $action,
            'created_at'     => $createdAt,
            'updated_at'     => $updatedAt,
            'deleted_at'     => $deletedAt,
            'entity_name'    => $entityName,
            'path'           => $request->getPathInfo(),
            'request_method' => $request->getMethod(),
            'changed_id'    => $changedId,
            'value'          => $value,
            'payload'        => $payload,
            'request_data'   => $request->getContent(),
            'request' => $request->attributes->all(),
            ' ' => $request->getBasePath()
        ];


        // Salvar arquivo
        $path = __DIR__ . '/../../var/log/log_' . date('Ymd_His') . '.json';
        // file_put_contents($path, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function normalize(object $entity): array
    {
        $data = [];
        foreach (get_class_methods($entity) as $method) {
            if (str_starts_with($method, 'get') && $method !== 'getId') {
                try {
                    $value = $entity->$method();
                    if (is_scalar($value) || $value instanceof \Stringable || $value === null) {
                        $field = lcfirst(str_replace('get', '', $method));
                        $data[$field] = $value;
                    }
                } catch (\Throwable) {
                }
            }
        }
        return $data;
    }
}
