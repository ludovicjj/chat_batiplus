<?php

namespace App\RequestHandler\Chatbot\Ask;

use App\Exception\ValidatorException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

readonly class RequestValidator
{
    public function __construct(
        private ValidatorInterface $validator
    ) {}

    /**
     * @throws ValidatorException
     */
    public function validate(array $data): void
    {
        $constraints = $this->getConstraints();

        $violations = $this->validator->validate($data, $constraints);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }

            // Send Exception
            throw new ValidatorException('Données invalides', $errors);
        }
    }

    private function getConstraints(): Assert\Collection
    {
        return new Assert\Collection([
            'question' => [
                new Assert\NotBlank(message: 'La question ne peut pas être vide'),
                new Assert\Type('string', message: 'La question doit être une chaîne de caractères'),
                new Assert\Length(
                    min: 3,
                    max: 500,
                    minMessage: 'La question doit contenir au moins {{ limit }} caractères',
                    maxMessage: 'La question ne peut pas dépasser {{ limit }} caractères'
                )
            ],
            'session_id' => [
                new Assert\Optional([
                    new Assert\Type('string'),
                    new Assert\Length(max: 100)
                ])
            ]
        ]);
    }
}