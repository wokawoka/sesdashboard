<?php


namespace App\Controller;


use App\Entity\Project;
use App\Repository\EmailRepository;
use App\Utils\WebHookProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebHookController extends BaseController
{
    /**
     * @Route("/webhook/{token}", name="app_webhook")
     */
    public function index(Project $project,
                          Request $request,
                          EntityManagerInterface $em,
                          EmailRepository $emailRepository,
                          WebHookProcessor $processor,
                          HttpClientInterface $httpClient)
    {

        $jsonData = json_decode($request->getContent(), true);

        if ($jsonData === false) {
            return new Response('Error', Response::HTTP_BAD_REQUEST);
        }

        // Auto subscribe to SNS topic.
        if (!empty($jsonData['Type']) && $jsonData['Type'] == 'SubscriptionConfirmation') {
            $response = $httpClient->request(
                'GET',
                $jsonData['SubscribeURL']
            );
            if ($response->getStatusCode() == Response::HTTP_OK) {
                return new Response('Ok');
            }
            return new Response('Not Ok', Response::HTTP_BAD_REQUEST);
        }

        // Process mail events.
        if ($jsonData['eventType'] == 'Send') {
            $email = $processor->createEmailFromJson($jsonData);
            $email->setProject($project);

            $em->persist($email);
            $emailEvent = $processor->createEmailEventFromJson($email, $jsonData, 'send');
            $em->persist($emailEvent);
        }
        else {
            $email = $emailRepository->findOneBy([
                'project' => $project,
                'messageId' => $jsonData['mail']['messageId'],
            ]);

            if ($jsonData === false) {
                return new Response('Not Found', Response::HTTP_NOT_FOUND);
            }

            try {
                $emailEvent = $processor->createEvent($email, $jsonData);
            } catch (\Exception $e) {
                return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
            }

            $em->persist($emailEvent);
        }

        $em->flush();

        return new Response('Ok');
    }
}