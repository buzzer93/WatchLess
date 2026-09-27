<?php

namespace App\Repository;

use App\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Video> */
class VideoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Video::class); }

    /** @return list<Video> */
    public function search(string $query = '', int $page = 1, int $limit = 24): array
    {
        $qb = $this->createQueryBuilder('v')->orderBy('v.createdAt', 'DESC')->addOrderBy('v.id', 'DESC');
        if ($query !== '') {
            $qb->andWhere('LOWER(v.title) LIKE :query')->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        return $qb->setFirstResult((max(1, $page) - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();
    }
}
