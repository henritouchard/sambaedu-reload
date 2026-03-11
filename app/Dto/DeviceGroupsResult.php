<?php

namespace App\Dto;

use App\LdapModels\DeviceGroupModel;

/**
 * DTO pour le résultat de ParcService::getGroups()
 */
readonly class DeviceGroupsResult
{
    /**
     * @param DeviceGroupModel|null $rootGroup Le groupe racine (OU de l'établissement)
     * @param DeviceGroupModel[] $groups Les groupes (salles/OU)
     */
    public function __construct(
        public ?DeviceGroupModel $rootGroup,
        public array $groups,
    ) {
    }
}
