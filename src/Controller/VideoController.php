<?php

namespace App\Controller;

use App\Repository\VideoRepository;
use App\Service\SubmitVideo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VideoController extends AbstractController
{
    #[Route('/videos/new', name: 'video_new', methods: ['GET'])]
    public function home(VideoRepository $videos): Response
    {
        return $this->render('video/home.html.twig', ['videos' => $videos->search(limit: 6), 'error' => null, 'url' => '']);
    }

    #[Route('/videos', name: 'video_submit', methods: ['POST'])]
    public function submit(Request $request, SubmitVideo $submit, VideoRepository $videos): Response
    {
        if (!$this->isCsrfTokenValid('submit-video', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Le formulaire a expiré. Rechargez la page.');
        }
        $url = $request->request->getString('url');
        try {
            $id = $submit->submit($url);
            return $this->redirectToRoute('video_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        } catch (\InvalidArgumentException $e) {
            return $this->render('video/home.html.twig', ['videos' => $videos->search(limit: 6), 'error' => $e->getMessage(), 'url' => $url], new Response(status: 422));
        }
    }

    #[Route('/videos', name: 'video_index', methods: ['GET'])]
    public function index(Request $request, VideoRepository $videos): Response
    {
        $query = mb_substr(trim($request->query->getString('q')), 0, 200);
        $page = max(1, $request->query->getInt('page', 1));
        return $this->render('video/index.html.twig', ['videos' => $videos->search($query, $page), 'query' => $query, 'page' => $page]);
    }

    #[Route('/videos/{id}', name: 'video_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, VideoRepository $videos, Request $request, EntityManagerInterface $em): Response
    {
        $video = $videos->find($id) ?? throw $this->createNotFoundException('Vidéo introuvable.');
        $topic = $request->query->getInt('topic') ? $em->find(\App\Entity\Topic::class, $request->query->getInt('topic')) : null;
        if ($topic && !$topic->videos->contains($video)) { $topic = null; }
        return $this->render('video/show.html.twig', ['video' => $video, 'topic' => $topic]);
    }

    #[Route('/videos/{id}/delete', name: 'video_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(int $id, Request $request, VideoRepository $videos, EntityManagerInterface $em, LockFactory $locks): Response
    {
        $video = $videos->find($id) ?? throw $this->createNotFoundException('Vidéo introuvable.');
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('delete-video-'.$id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Le formulaire a expiré. Rechargez la page.');
            }
            // Share the worker lock so an active analysis cannot write after deletion.
            $lock = $locks->createLock('analyze-'.$id);
            if ($lock->acquire()) {
                try {
                    $em->wrapInTransaction(function () use ($em, $video): void {
                        $em->getConnection()->executeStatement("UPDATE topic SET revision = revision + 1, status = 'idle', error_message = NULL WHERE id IN (SELECT topic_id FROM topic_video WHERE video_id = ?)", [$video->id]);
                        $em->remove($video);
                        $em->flush();
                    });
                } finally { $lock->release(); }
                $this->addFlash('success', 'La vidéo et ses textes ont été supprimés de WatchLess.');
                return $this->redirectToRoute('video_index', status: Response::HTTP_SEE_OTHER);
            }
            $error = 'Cette vidéo est en cours de traitement. Réessayez lorsque l’analyse sera terminée.';
        }
        return $this->render('video/delete.html.twig', ['video' => $video, 'error' => $error], new Response(status: $error ? 409 : 200));
    }

    #[Route('/videos/{id}/regenerate', name: 'video_regenerate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function regenerate(int $id, Request $request, VideoRepository $videos, \App\Service\RestartVideoSummary $restart, EntityManagerInterface $em): Response
    {
        $video = $videos->find($id) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('regenerate-video-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Le formulaire a expiré. Rechargez la page.');
        }
        try {
            $started = $restart->restart($id);
            $this->addFlash('success', $started ? 'La régénération du compte rendu est en attente. La transcription existante sera réutilisée.' : 'Un traitement est déjà en cours pour cette vidéo.');
        } catch (\InvalidArgumentException $e) { $this->addFlash('error', $e->getMessage()); }
        $params = ['id' => $id, '_fragment' => 'summary'];
        $topicId = $request->request->getInt('topic');
        if ($topicId && ($topic = $em->find(\App\Entity\Topic::class, $topicId)) && $topic->videos->contains($video)) { $params['topic'] = $topicId; }
        return $this->redirectToRoute('video_show', $params, Response::HTTP_SEE_OTHER);
    }

    #[Route('/videos/{id}/status', name: 'video_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function status(int $id, VideoRepository $videos): Response
    {
        $video = $videos->find($id) ?? throw $this->createNotFoundException();
        $response = $this->json(['status' => $video->getStatus()->value, 'label' => $video->getStatus()->label(), 'terminal' => $video->getStatus()->terminal()]);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}
