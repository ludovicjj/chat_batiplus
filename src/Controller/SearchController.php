<?php

namespace App\Controller;

use App\Service\ElasticsearchSearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    #[Route('/search', name: 'app_search')]
    public function search(
        Request $request,
        ElasticsearchSearchService $searchService
    ): Response {
        $results = [];
        $total = 0;

        if ($request->isMethod('POST') || $request->query->count() > 0) {
            // Récupération des critères depuis le formulaire
            $criteria = [
                'reference' => trim($request->request->get('reference', '')),
                'projectName' => trim($request->request->get('projectName', '')),
                'agencyName' => trim($request->request->get('agencyName', '')),
                'clientName' => trim($request->request->get('clientName', '')),
                'status' => trim($request->request->get('status', '')),
                'global' => trim($request->request->get('global', ''))
            ];

            // Supprimer les critères vides
            // 94P0242305
            $criteria = array_filter($criteria, fn($value) => !empty($value));

            if (!empty($criteria)) {
                $searchResults = $searchService->searchClientCases($criteria, 50);
                $results = $searchResults['results'];
                $total = $searchResults['total'];
            }
        }

        return $this->render('search/index.html.twig', [
            'results' => $results,
            'total' => $total,
            'criteria' => $criteria ?? []
        ]);
    }
}