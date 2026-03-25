<?php

namespace App\Account;

use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UserAvatarManager
{
    private const MAX_AVATAR_SIZE_BYTES = 5_242_880; // 5 MB
    private const TARGET_AVATAR_SIZE = 256;
    private const WEBP_QUALITY = 82;
    private const JPEG_QUALITY = 84;
    private const PUBLIC_AVATAR_PREFIX = '/uploads/avatars/';

    /**
     * @var array<string>
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private readonly string $storageDirectory;

    public function __construct()
    {
        $this->storageDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'octoflow-user-avatars';
    }

    public function storeUploadedAvatar(User $user, UploadedFile $uploadedAvatar): void
    {
        $this->validateUploadedAvatar($uploadedAvatar);

        $rawImageContent = @file_get_contents($uploadedAvatar->getPathname());
        if (!is_string($rawImageContent) || $rawImageContent === '') {
            throw new \InvalidArgumentException('Nao foi possivel ler o arquivo enviado.');
        }

        $sourceImage = @imagecreatefromstring($rawImageContent);
        if ($sourceImage === false) {
            throw new \InvalidArgumentException('Arquivo de imagem invalido.');
        }

        $optimizedImage = null;

        try {
            $optimizedImage = $this->buildSquareAvatarImage($sourceImage);
            [$publicAvatarPath, $mimeType] = $this->saveOptimizedAvatar($user, $optimizedImage);
            $this->deleteAvatarFileByPath($user->getAvatarPath());

            $user->setAvatarPath($publicAvatarPath);
            $user->setAvatarMimeType($mimeType);
            $user->setAvatarUpdatedAt(new \DateTimeImmutable());
        } finally {
            if (is_resource($sourceImage) || $sourceImage instanceof \GdImage) {
                imagedestroy($sourceImage);
            }

            if ($optimizedImage !== null && (is_resource($optimizedImage) || $optimizedImage instanceof \GdImage)) {
                imagedestroy($optimizedImage);
            }
        }
    }

    public function removeAvatar(User $user): void
    {
        $this->deleteAvatarFileByPath($user->getAvatarPath());

        $user->setAvatarPath(null);
        $user->setAvatarMimeType(null);
        $user->setAvatarUpdatedAt(null);
    }

    public function resolveAbsolutePathFromAvatarPath(?string $avatarPath): ?string
    {
        $fileName = $this->extractAvatarFileName($avatarPath);
        if ($fileName === null) {
            return null;
        }

        return $this->storageDirectory . DIRECTORY_SEPARATOR . $fileName;
    }

    private function validateUploadedAvatar(UploadedFile $uploadedAvatar): void
    {
        if (!$uploadedAvatar->isValid()) {
            throw new \InvalidArgumentException('Upload de imagem invalido.');
        }

        $uploadedSize = $uploadedAvatar->getSize();
        if (!is_int($uploadedSize) || $uploadedSize <= 0) {
            throw new \InvalidArgumentException('Nao foi possivel validar o tamanho da imagem.');
        }

        if ($uploadedSize > self::MAX_AVATAR_SIZE_BYTES) {
            throw new \InvalidArgumentException('A imagem deve ter no maximo 5 MB.');
        }

        $mimeType = trim((string) $uploadedAvatar->getMimeType());
        if ($mimeType === '' || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Formato de imagem nao suportado. Use JPG, PNG ou WEBP.');
        }
    }

    private function buildSquareAvatarImage(\GdImage $sourceImage): \GdImage
    {
        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        $shortSide = min($sourceWidth, $sourceHeight);

        if ($shortSide <= 0) {
            throw new \InvalidArgumentException('Nao foi possivel processar as dimensoes da imagem.');
        }

        $sourceOffsetX = max(0, intdiv($sourceWidth - $shortSide, 2));
        $sourceOffsetY = max(0, intdiv($sourceHeight - $shortSide, 2));

        $targetImage = imagecreatetruecolor(self::TARGET_AVATAR_SIZE, self::TARGET_AVATAR_SIZE);
        if ($targetImage === false) {
            throw new \RuntimeException('Nao foi possivel preparar a imagem otimizada.');
        }

        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);

        $resampleSucceeded = imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            $sourceOffsetX,
            $sourceOffsetY,
            self::TARGET_AVATAR_SIZE,
            self::TARGET_AVATAR_SIZE,
            $shortSide,
            $shortSide,
        );

        if (!$resampleSucceeded) {
            imagedestroy($targetImage);
            throw new \RuntimeException('Nao foi possivel redimensionar a imagem enviada.');
        }

        return $targetImage;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function saveOptimizedAvatar(User $user, \GdImage $optimizedImage): array
    {
        $this->ensureStorageDirectoryExists();

        $userId = (int) ($user->getId() ?? 0);
        if ($userId <= 0) {
            throw new \RuntimeException('Nao foi possivel identificar o usuario da imagem de perfil.');
        }

        $fileNameSeed = sprintf('user_%d_%s', $userId, bin2hex(random_bytes(8)));
        $targetMimeType = 'image/webp';
        $fileExtension = 'webp';

        if (!function_exists('imagewebp')) {
            $targetMimeType = 'image/jpeg';
            $fileExtension = 'jpg';
        }

        $targetFileName = sprintf('%s.%s', $fileNameSeed, $fileExtension);
        $targetAbsolutePath = $this->storageDirectory . DIRECTORY_SEPARATOR . $targetFileName;

        $writeSucceeded = $targetMimeType === 'image/webp'
            ? imagewebp($optimizedImage, $targetAbsolutePath, self::WEBP_QUALITY)
            : imagejpeg($optimizedImage, $targetAbsolutePath, self::JPEG_QUALITY);

        if ($writeSucceeded !== true) {
            throw new \RuntimeException('Nao foi possivel salvar a imagem de perfil otimizada.');
        }

        return [
            self::PUBLIC_AVATAR_PREFIX . $targetFileName,
            $targetMimeType,
        ];
    }

    private function ensureStorageDirectoryExists(): void
    {
        if (is_dir($this->storageDirectory)) {
            return;
        }

        if (!@mkdir($this->storageDirectory, 0o755, true) && !is_dir($this->storageDirectory)) {
            throw new \RuntimeException('Nao foi possivel preparar o diretorio de imagens de perfil.');
        }
    }

    private function deleteAvatarFileByPath(?string $avatarPath): void
    {
        $fileName = $this->extractAvatarFileName($avatarPath);
        if ($fileName === null) {
            return;
        }

        $absolutePath = $this->storageDirectory . DIRECTORY_SEPARATOR . $fileName;
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private function extractAvatarFileName(?string $avatarPath): ?string
    {
        $normalizedAvatarPath = trim((string) $avatarPath);
        if ($normalizedAvatarPath === '') {
            return null;
        }

        if (!str_starts_with($normalizedAvatarPath, self::PUBLIC_AVATAR_PREFIX)) {
            return null;
        }

        $fileName = basename($normalizedAvatarPath);
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._-]{8,255}$/', $fileName) !== 1) {
            return null;
        }

        return $fileName;
    }
}
