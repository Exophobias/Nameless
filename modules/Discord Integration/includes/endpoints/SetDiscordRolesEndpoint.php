<?php

/**
 * @param int $user The NamelessMC user ID to edit
 * @param string $roles An array of Discord Role ID to give to the user
 * @deprecated Use SyncDiscordRolesEndpoint instead
 * @return string JSON Array
 */
class SetDiscordRolesEndpoint extends KeyAuthEndpoint {

    public function __construct() {
        $this->_route = 'discord/set-roles';
        $this->_module = 'Discord Integration';
        $this->_description = 'Set a NamelessMC user\'s according to the supplied Discord Role ID list';
        $this->_method = 'POST';
    }

    public function execute(Nameless2API $api): void {
        $api->validateParams($_POST, ['user']);

        if (!Discord::isBotSetup()) {
            $api->throwError(DiscordApiErrors::ERROR_DISCORD_INTEGRATION_DISABLED);
        }

        $user = $api->getUser('id', $_POST['user']);

        // This deprecated whole-role-list endpoint has no source Discord identity.
        // It could grant a staff group from an old account's event, or remove one
        // when the supplied list omits the role. Keep ordinary nonstaff calls.
        $roles = $_POST['roles'] ?? [];
        if (!is_array($roles)) {
            $api->throwError(Nameless2API::ERROR_INVALID_POST_CONTENTS);
        }
        $discordStaffRoles = [
            '665323124895645725' => true, // Helper
            '665323333876973589' => true, // Moderator
            '665322860578996254' => true, // Administrator
            '657096201665249312' => true, // Senior Administrator
        ];
        foreach ($roles as $roleId) {
            if (!is_string($roleId) && !is_int($roleId)) {
                $api->throwError(Nameless2API::ERROR_INVALID_POST_CONTENTS);
            }
            if (!preg_match('/^[1-9][0-9]{16,19}$/D', (string) $roleId)) {
                $api->throwError(Nameless2API::ERROR_INVALID_POST_CONTENTS);
            }
            if (isset($discordStaffRoles[(string) $roleId])) {
                $api->throwError(Nameless2API::ERROR_NOT_AUTHORIZED,
                    'Use identity-scoped sync-roles for Discord-managed staff.', 409);
            }
        }
        $staffGroups = DB::getInstance()->getPDO()->prepare(
            'SELECT 1 FROM nl2_users_groups WHERE user_id = ? AND group_id IN (8, 3, 7, 6) LIMIT 1'
        );
        if (!$staffGroups || !$staffGroups->execute([$user->data()->id])) {
            $api->throwError(Nameless2API::ERROR_UNKNOWN_ERROR, 'Staff role guard unavailable.', 503);
        }
        if ($staffGroups->fetchColumn() !== false) {
            $api->throwError(Nameless2API::ERROR_NOT_AUTHORIZED,
                'Use identity-scoped sync-roles for Discord-managed staff.', 409);
        }

        $log_array = GroupSyncManager::getInstance()->broadcastChange(
            $user,
            DiscordGroupSyncInjector::class,
            $roles
        );

        if (count($log_array)) {
            Log::getInstance()->log(Log::Action('discord/role_set'), json_encode($log_array), $user->data()->id);
        }

        $api->returnArray(array_merge(['message' => Discord::getLanguageTerm('group_updated')], $log_array));
    }
}
