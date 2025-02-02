<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\AclBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Acl\Domain\ObjectIdentity;
use Symfony\Component\Security\Acl\Domain\RoleSecurityIdentity;
use Symfony\Component\Security\Acl\Domain\UserSecurityIdentity;
use Symfony\Component\Security\Acl\Exception\AclAlreadyExistsException;
use Symfony\Component\Security\Acl\Model\MutableAclProviderInterface;
use Symfony\Component\Security\Acl\Permission\MaskBuilder;
use Symfony\Component\Security\Acl\Permission\MaskBuilderRetrievalInterface;

/**
 * Sets ACL for objects.
 *
 * @author Kévin Dunglas <kevin@les-tilleuls.coop>
 */
final class SetAclCommand extends Command
{
    protected static $defaultName = 'acl:set';

    private $provider;
    private $maskBuilderRetrieval;

    public function __construct(MutableAclProviderInterface $provider, MaskBuilderRetrievalInterface $maskBuilderRetrieval = null)
    {
        parent::__construct();

        $this->provider = $provider;
        $this->maskBuilderRetrieval = $maskBuilderRetrieval ?? new class implements MaskBuilderRetrievalInterface {
            public function getMaskBuilder()
            {
                return new MaskBuilder();
            }
        };
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Sets ACL for objects')
            ->setHelp(<<<EOF
The <info>%command.name%</info> command sets ACL.
The ACL system must have been initialized with the <info>acl:init</info> command.

To set <comment>VIEW</comment> and <comment>EDIT</comment> permissions for the user <comment>kevin</comment> on the instance of
<comment>Acme\MyClass</comment> having the identifier <comment>42</comment>:

  <info>php %command.full_name% --user=Symfony/Component/Security/Core/User/User:kevin VIEW EDIT Acme/MyClass:42</info>

Note that you can use <comment>/</comment> instead of <comment>\\ </comment>for the namespace delimiter to avoid any
problem.

To set permissions for a role, use the <info>--role</info> option:

  <info>php %command.full_name% --role=ROLE_USER VIEW Acme/MyClass:1936</info>

To set permissions at the class scope, use the <info>--class-scope</info> option:

  <info>php %command.full_name% --class-scope --user=Symfony/Component/Security/Core/User/User:anne OWNER Acme/MyClass:42</info>

EOF
            )
            ->addArgument('arguments', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'A list of permissions and object identities (class name and ID separated by a column)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A list of security identities')
            ->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A list of roles')
            ->addOption('class-scope', null, InputOption::VALUE_NONE, 'Use class-scope entries');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Parse arguments
        $objectIdentities = [];
        $maskBuilder = $this->maskBuilderRetrieval->getMaskBuilder();
        foreach ($input->getArgument('arguments') as $argument) {
            $data = explode(':', $argument, 2);

            if (\count($data) > 1) {
                $objectIdentities[] = new ObjectIdentity($data[1], strtr($data[0], '/', '\\'));
            } else {
                $maskBuilder->add($data[0]);
            }
        }

        // Build permissions mask
        $mask = $maskBuilder->get();

        $userOption = $input->getOption('user');
        $roleOption = $input->getOption('role');
        $classScopeOption = $input->getOption('class-scope');

        if (empty($userOption) && empty($roleOption)) {
            throw new \InvalidArgumentException('A Role or a User must be specified.');
        }

        // Create security identities
        $securityIdentities = [];

        if ($userOption) {
            foreach ($userOption as $user) {
                $data = explode(':', $user, 2);

                if (1 === \count($data)) {
                    throw new \InvalidArgumentException('The user must follow the format "Acme/MyUser:username".');
                }

                $securityIdentities[] = new UserSecurityIdentity($data[1], strtr($data[0], '/', '\\'));
            }
        }

        if ($roleOption) {
            foreach ($roleOption as $role) {
                $securityIdentities[] = new RoleSecurityIdentity($role);
            }
        }

        // Sets ACL
        foreach ($objectIdentities as $objectIdentity) {
            // Creates a new ACL if it does not already exist
            try {
                $this->provider->createAcl($objectIdentity);
            } catch (AclAlreadyExistsException $e) {
            }

            $acl = $this->provider->findAcl($objectIdentity, $securityIdentities);

            foreach ($securityIdentities as $securityIdentity) {
                if ($classScopeOption) {
                    $acl->insertClassAce($securityIdentity, $mask);
                } else {
                    $acl->insertObjectAce($securityIdentity, $mask);
                }
            }

            $this->provider->updateAcl($acl);
        }

        return 0;
    }
}
