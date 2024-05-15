<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity as EntityAttr;
use Doctrine\ORM\Mapping\Id;

#[EntityAttr]
class Entity
{

    #[Id]
    #[Column(type: 'string', nullable: false)]
    private string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }

}
