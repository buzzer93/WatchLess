<?php
namespace App\Controller;

use App\Entity\Topic;
use App\Entity\TopicPublication;
use App\Entity\Video;
use App\Message\GenerateTopicPublication;
use App\Message\SummarizeTopic;
use App\Service\SubmitVideo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class TopicController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    #[Route('/topics', name: 'topic_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'create-topic');
            $name = trim($request->request->getString('name'));
            if ($name === '' || mb_strlen($name) > 200) { $error = 'Indiquez un nom de sujet de 1 à 200 caractères.'; }
            else {
                $topic = new Topic($name);
                $intent = $request->request->getString('intent');
                if (!array_key_exists($intent, Topic::intents())) { $intent = ''; }
                $topic->intent = $intent;
                $em->persist($topic);
                $em->flush();
                return $this->redirectToRoute('topic_show', ['id' => $topic->id], 303);
            }
        }
        return $this->render('topic/index.html.twig', ['topics' => $em->getRepository(Topic::class)->findBy([], ['updatedAt' => 'DESC']), 'error' => $error], new Response(status: $error ? 422 : 200));
    }

    #[Route('/topics/{id}', name: 'topic_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, EntityManagerInterface $em): Response
    {
        $topic = $em->find(Topic::class, $id) ?? throw $this->createNotFoundException();
        $available = array_filter($em->getRepository(Video::class)->findBy([], ['createdAt' => 'DESC']), fn (Video $video) => !$topic->videos->contains($video));
        return $this->render('topic/show.html.twig', ['topic' => $topic, 'available' => $available, 'publicationFormats' => TopicPublication::labels(), 'topicIntents' => Topic::intents()]);
    }

    #[Route('/topics/{id}/update', name: 'topic_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(int $id, Request $request, EntityManagerInterface $em, LockFactory $locks, MessageBusInterface $bus, SubmitVideo $submit): Response
    {
        $this->checkToken($request, 'topic-'.$id);
        $lock = $locks->createLock('topic-edit-'.$id);
        $lock->acquire(true);
        $db = $em->getConnection();
        try {
            // Acquire the per-video submission lock before opening a SQLite transaction.
            if ($request->request->getString('action') === 'import') {
                $topic = $em->find(Topic::class, $id) ?? throw $this->createNotFoundException();
                $em->refresh($topic);
                $submit->submit($request->request->getString('url'), $topic);
                $this->addFlash('success', 'La vidéo est dans ce sujet. Son compte rendu sera affiché dès qu’il sera prêt.');
                return $this->redirectToRoute('topic_show', ['id' => $id], 303);
            }
            $db->beginTransaction();
            $topic = $em->find(Topic::class, $id) ?? throw $this->createNotFoundException();
            $em->refresh($topic);
            switch ($request->request->getString('action')) {
                case 'edit':
                    $name = trim($request->request->getString('name'));
                    $description = trim($request->request->getString('description'));
                    $context = trim($request->request->getString('context'));
                    $intent = $request->request->getString('intent');
                    if (!array_key_exists($intent, Topic::intents())) { throw new \InvalidArgumentException('Intention de sujet inconnue.'); }
                    if ($name === '' || mb_strlen($name) > 200 || mb_strlen($description) > 2000 || mb_strlen($context) > 2000) {
                        throw new \InvalidArgumentException('Nom requis (200 caractères maximum) ; description et contexte limités à 2 000 caractères chacun.');
                    }
                    if ([$topic->name, $topic->description, $topic->context, $topic->intent] !== [$name, $description, $context, $intent]) {
                        $topic->name = $name; $topic->description = $description; $topic->context = $context; $topic->intent = $intent;
                        $topic->changed();
                    }
                    break;
                case 'add':
                    $video = $em->find(Video::class, $request->request->getInt('video_id')) ?? throw new \InvalidArgumentException('Vidéo introuvable.');
                    if (!$topic->videos->contains($video)) { $topic->videos->add($video); $topic->changed(); }
                    break;
                case 'remove':
                    foreach ($topic->videos as $video) {
                        if ($video->id === $request->request->getInt('video_id')) { $topic->videos->removeElement($video); $topic->changed(); break; }
                    }
                    break;
                case 'generate':
                    if (!$topic->isBusy()) {
                        if ($error = $topic->generationError()) { throw new \InvalidArgumentException($error); }
                        $topic->status = 'pending'; $topic->errorMessage = null;
                        $topic->updatedAt = new \DateTimeImmutable();
                        $em->flush();
                        $bus->dispatch(new SummarizeTopic($id, $topic->revision));
                    }
                    break;
                case 'publish':
                    $format = $request->request->getString('format');
                    if (!array_key_exists($format, TopicPublication::labels())) { throw new \InvalidArgumentException('Format de publication inconnu.'); }
                    if (!$topic->summary || $topic->isStale() || $topic->summaryRevision !== $topic->revision) {
                        throw new \InvalidArgumentException('Générez d’abord un compte rendu global à jour pour ce sujet.');
                    }
                    $publication = $em->getRepository(TopicPublication::class)->findOneBy(['topic' => $topic, 'format' => $format]);
                    if (!$publication) { $publication = new TopicPublication($topic, $format); $em->persist($publication); }
                    if (!$publication->isBusy()) {
                        $publication->status = 'pending';
                        $publication->sourceRevision = $topic->revision;
                        $publication->errorMessage = null;
                        $publication->updatedAt = new \DateTimeImmutable();
                        $em->flush();
                        $bus->dispatch(new GenerateTopicPublication($publication->id, $id, $topic->revision, $format));
                    }
                    break;
                default: throw new \InvalidArgumentException('Action inconnue.');
            }
            $em->flush();
            $db->commit();
        } catch (\InvalidArgumentException $e) {
            if ($db->isTransactionActive()) { $db->rollBack(); }
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            if ($db->isTransactionActive()) { $db->rollBack(); }
            throw $e;
        } finally { $lock->release(); }
        return $this->redirectToRoute('topic_show', ['id' => $id], 303);
    }

    #[Route('/topics/{id}/status', name: 'topic_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function status(int $id, EntityManagerInterface $em): Response
    {
        $topic = $em->find(Topic::class, $id) ?? throw $this->createNotFoundException();
        $response = $this->json(['status' => $topic->progressKey(), 'label' => $topic->statusLabel(), 'terminal' => !$topic->hasWorkInProgress()]);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Le formulaire a expiré. Rechargez la page.'); }
    }
}
