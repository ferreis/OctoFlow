<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Create a user for JWT authentication tests.',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('password', InputArgument::REQUIRED, 'User password')
            ->addOption('roles', null, InputOption::VALUE_OPTIONAL, 'Comma-separated roles', 'ROLE_USER')
            ->addOption('inactive', null, InputOption::VALUE_NONE, 'Create the user with inactive status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $password = (string) $input->getArgument('password');

        if ($this->userRepository->findOneByEmail($email) !== null) {
            $io->error(sprintf('User "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $rolesInput = (string) $input->getOption('roles');
        $roles = array_filter(array_map('trim', explode(',', strtoupper($rolesInput))));
        $roles[] = 'ROLE_USER';

        $user = (new User())
            ->setEmail($email)
            ->setRoles(array_values(array_unique($roles)))
            ->setIsActive(!$input->getOption('inactive'));

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('User "%s" created with roles: %s', $user->getEmail(), implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}
