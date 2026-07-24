<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\AccountResolver;
use App\Service\SubmitterResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Überführt einen anonymen Edit-Token-Submitter ins End-Nutzer-Konto
 * (Phase 3, Edit-Token-Migration). Besitzbeweis beider Geheimnisse in einem
 * Request: gacc_-Bearer im Header, gsti_-Edit-Token im Body. Das Edit-Token
 * bleibt danach gültig (beide Wege parallel). Idempotent für dasselbe Konto.
 */
final class AccountClaimController
{
    #[Route('/api/account/claims', methods: ['POST'])]
    public function __invoke(
        Request $request,
        AccountResolver $accounts,
        SubmitterResolver $submitters,
        EntryRepository $entries,
        EntityManagerInterface $em,
    ): JsonResponse {
        $account = $accounts->requireAccount($request);

        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        $editToken = $body['editToken'] ?? null;
        if (!\is_string($editToken) || $editToken === '') {
            throw new ApiProblem(400, 'editToken is required');
        }

        $submitter = $submitters->resolveFromEditToken($editToken, $request);

        if ($submitter->banned) {
            // Ein gesperrter Ruf lässt sich nicht in ein Konto einbringen.
            throw new ApiProblem(403, 'Submitter is banned');
        }
        if ($submitter->account !== null && $submitter->account->id !== $account->id) {
            throw new ApiProblem(409, 'Submitter already claimed by another account');
        }

        $submitter->account = $account;
        $em->flush();

        return new JsonResponse(['entries' => $entries->count(['submitter' => $submitter])]);
    }
}
