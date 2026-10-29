<?php

namespace App\Entity;

/** How a learner attends classes, as SF1 records it */
enum LearningModality: string
{
    case FaceToFace = 'face_to_face';
    case ModularPrint = 'modular_print';
    case ModularDigital = 'modular_digital';
    case Online = 'online';
    case Tv = 'tv';
    case Radio = 'radio';
    case Homeschooling = 'homeschooling';
    case Blended = 'blended';

    public function label(): string
    {
        return match ($this) {
            self::FaceToFace => 'Face to Face',
            self::ModularPrint => 'Modular (Print)',
            self::ModularDigital => 'Modular (Digital)',
            self::Online => 'Online',
            self::Tv => 'Educational TV',
            self::Radio => 'Radio-Based Instruction',
            self::Homeschooling => 'Homeschooling',
            self::Blended => 'Blended',
        };
    }
}
