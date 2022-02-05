<?php

namespace Mosparo\Controller;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Mosparo\Exception\UserAlreadyExistsException;
use Mosparo\Form\PasswordFormType;
use Mosparo\Helper\SetupHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/setup")
 */
class SetupController extends AbstractController
{
    protected $kernel;

    protected $setupHelper;

    public function __construct(KernelInterface $kernel, SetupHelper $setupHelper)
    {
        $this->kernel = $kernel;
        $this->setupHelper = $setupHelper;
    }

    /**
     * @Route("/", name="setup_start")
     */
    public function start(): Response
    {
        return $this->render('setup/start.html.twig');
    }

    /**
     * @Route("/prerequisites", name="setup_prerequisites")
     */
    public function prerequisites(Request $request): Response
    {
        [ $meetPrerequisites, $prerequisites ] = $this->setupHelper->checkPrerequisites();

        return $this->render('setup/prerequisites.html.twig', [
            'meetPrerequisites' => $meetPrerequisites,
            'prerequisites' => $prerequisites
        ]);
    }

    /**
     * @Route("/database", name="setup_database")
     */
    public function database(Request $request): Response
    {
        $form = $this->createFormBuilder([], ['translation_domain' => 'mosparo'])
            ->add('host', TextType::class, ['label' => 'setup.database.form.host'])
            ->add('port', TextType::class, ['label' => 'setup.database.form.port', 'required' => false, 'data' => 3306])
            ->add('database', TextType::class, ['label' => 'setup.database.form.database'])
            ->add('user', TextType::class, ['label' => 'setup.database.form.user'])
            ->add('password', PasswordType::class, ['label' => 'setup.database.form.password'])
            ->getForm();

        $form->handleRequest($request);
        $connected = false;
        if ($form->isSubmitted() && $form->isValid()) {
            $data = [
                'host' => $form->get('host')->getData(),
                'port' => $form->get('port')->getData(),
                'database' => $form->get('database')->getData(),
                'user' => $form->get('user')->getData(),
                'password' => $form->get('password')->getData()
            ];

            $dsn = sprintf('mysql://%s:%s@%s:%d/%s', $data['user'], urlencode($data['password']), $data['host'], intval($data['port']), $data['database']);

            $connectionParams = [ 'url' => $dsn ];
            $connection = DriverManager::getConnection($connectionParams);
            try {
                $connection->connect();
                $connected = $connection->isConnected();

                $request->getSession()->set('setupDatabaseDsn', $dsn);
            } catch (ConnectionException $e) {
                $connected = false;
            }

            // Save the database connection in the session and continue to mail setup
            if ($connected) {
                return $this->redirectToRoute('setup_mail');
            }
        }

        return $this->render('setup/database.html.twig', [
            'form' => $form->createView(),
            'submitted' => $form->isSubmitted(),
            'connected' => $connected,
        ]);
    }

    /**
     * @Route("/mail", name="setup_mail")
     */
    public function mail(Request $request): Response
    {
        $form = $this->createFormBuilder([], ['translation_domain' => 'mosparo'])
            ->add('useSmtp', CheckboxType::class, ['label' => 'setup.mail.form.useSmtp', 'required' => false])
            ->add('host', TextType::class, ['label' => 'setup.mail.form.host', 'attr' => ['disabled' => true, 'class' => 'mail-option']])
            ->add('port', TextType::class, ['label' => 'setup.mail.form.port', 'required' => false, 'data' => 25, 'attr' => ['disabled' => true, 'class' => 'mail-option']])
            ->add('user', TextType::class, ['label' => 'setup.mail.form.user', 'attr' => ['disabled' => true, 'class' => 'mail-option']])
            ->add('password', PasswordType::class, ['label' => 'setup.mail.form.password', 'attr' => ['disabled' => true, 'class' => 'mail-option']])
            ->getForm();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $useSmtp = $form->get('useSmtp')->getData();
            if ($useSmtp) {
                $data = [
                    'host' => $form->get('host')->getData(),
                    'port' => $form->get('port')->getData(),
                    'user' => $form->get('user')->getData(),
                    'password' => $form->get('password')->getData()
                ];

                $dsn = sprintf('smtp://%s:%s@%s:%d', urlencode($data['user']), urlencode($data['password']), $data['host'], intval($data['port']));
            } else {
                $dsn = 'sendmail://default';
            }

            $request->getSession()->set('setupMailerDsn', $dsn);

            return $this->redirectToRoute('setup_other');
        }

        return $this->render('setup/mail.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/other", name="setup_other")
     */
    public function other(Request $request): Response
    {
        $form = $this->createFormBuilder([], ['translation_domain' => 'mosparo'])
            ->add('name', TextType::class, ['label' => 'setup.other.form.name'])
            ->add('emailAddress', TextType::class, ['label' => 'setup.other.form.emailAddress'])
            ->add('password', PasswordFormType::class, [
                'label' => 'setup.other.form.password',
                'mapped' => false,
                'required' => true,
                'is_new_password' => false,
            ])
            ->getForm();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $request->getSession()->set('setupMosparoName', $form->get('name')->getData());
            $request->getSession()->set('setupUserEmailAddress', $form->get('emailAddress')->getData());
            $request->getSession()->set('setupUserPassword', $form->get('password')->get('plainPassword')->getData());

            return $this->redirectToRoute('setup_configure');
        }

        return $this->render('setup/other.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    public function configure(Request $request): Response
    {
        // Create .env.local
        $session = $request->getSession();
        $this->setupHelper->saveEnvLocal([
            'MOSPARO_NAME' => $session->get('setupMosparoName'),
            'DATABASE_URL' => $session->get('setupDatabaseDsn'),
            'MAILER_DSN' => $session->get('setupMailerDsn'),
            'ENCRYPTION_KEY' => $this->setupHelper->generateEncryptionKey(),
            'MOSPARO_INSTALLED' => true
        ]);

        return $this->redirectToRoute('setup_install');
    }

    /**
     * @Route("/install", name="setup_install")
     */
    public function install(Request $request): Response
    {
        $session = $request->getSession();

        // Prepare database and execute the migrations
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput(array(
            'command' => 'doctrine:migrations:migrate',
            '-n'
        ));

        $output = new BufferedOutput();
        $application->run($input, $output);

        // Create user
        try {
            $this->setupHelper->createUser($session->get('setupUserEmailAddress'), $session->get('setupUserPassword'));
        } catch (UserAlreadyExistsException $e) {
            // Ignore this exception since the user exists, everything should be good.
        }

        return $this->render('setup/install.html.twig', [
        ]);
    }
}