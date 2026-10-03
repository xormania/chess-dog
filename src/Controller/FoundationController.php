<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FoundationController extends AbstractController
{
    #[Route('/foundation', name: 'app_foundation', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('foundation/index.html.twig');
    }
}
