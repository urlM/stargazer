<?php

namespace App\Controller;

use App\Entity\Test;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/test')]
class TestController extends AbstractController
{
    #[Route('/', name: 'test_index')]
    public function index(EntityManagerInterface $em): Response
    {
        return $this->render('tests.html.twig', [
            'tests' => $em->getRepository(Test::class)->findAll()
        ]);
    }

    #[Route('/add', name: 'test_add', methods: ['POST'])]
    public function create(EntityManagerInterface $em): Response
    {
        $test = (new Test())
            ->setName(uniqid())
            ->setDescription('This test was created on ' . date('n/j/Y H:i:s'));
        $em->persist($test);
        $em->flush();
        return $this->redirectToRoute('test_index');
    }

    #[Route('/clear', name: 'test_clear', methods: ['POST'])]
    public function clear(EntityManagerInterface $em): Response
    {
        $em->getRepository(Test::class)
            ->createQueryBuilder('t')
            ->delete()
            ->getQuery()
            ->execute();
        $em->flush();
        return $this->redirectToRoute('test_index');
    }
}
