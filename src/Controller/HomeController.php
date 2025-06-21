<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->render('chat/index.html.twig', [
            'title' => 'ChatBot BatiPlus'
        ]);
    }

    #[Route('/stream', name: 'home_stream')]
    public function testStream(): Response
    {
        return $this->render('chat/stream.html.twig', [
            'title' => 'ChatBot BatiPlus'
        ]);
    }
}
