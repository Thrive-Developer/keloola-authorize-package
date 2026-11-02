<?php

namespace Keloola\KeloolaSsoAuthorize\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Keloola\KeloolaSsoAuthorize\Integration\IntegrationException;
use Keloola\KeloolaSsoAuthorize\Integration\IntegrationHandler;

class SsoIntegrationController
{
    public function __invoke(Request $request, IntegrationHandler $handler)
    {
        $operation = $request->route()->defaults['operation'];
        $context = array_intersect_key($request->route()->parameters(), array_flip(['organizationId', 'userId', 'roleId', 'invitationId']));
        $contextValidator = Validator::make($context, [
            'organizationId' => 'sometimes|required|uuid',
            'userId' => 'sometimes|required|uuid',
            'roleId' => 'sometimes|required|integer|min:1',
            'invitationId' => 'sometimes|required|string|max:128',
        ]);
        if ($contextValidator->fails()) return $this->invalid($contextValidator->errors()->toArray());
        $hasBody = in_array($request->method(), ['POST', 'PUT', 'PATCH'], true);
        if ($hasBody && (!$request->isJson() || !is_array(json_decode($request->getContent(), true)) || !str_starts_with(ltrim($request->getContent()), '{'))) {
            return response()->json(['is_success' => false, 'error' => 'invalid_json', 'message' => 'A JSON object is required.'], 422);
        }
        $input = $hasBody ? $request->json()->all() : [];
        $validator = Validator::make($input, $this->rules($operation));
        if ($validator->fails()) return $this->invalid($validator->errors()->toArray());
        $payload = $validator->validated();
        if ($operation === 'organizations.update' && $payload === []) {
            return $this->invalid(['payload' => ['At least one organization field is required.']]);
        }
        if ($operation === 'organizations.provision') {
            $payload['owner'] = array_intersect_key($payload['owner'], array_flip(['sso_id', 'name', 'email']));
        }
        try {
            $data = $handler->handle($operation, $context, $payload);
            return response()->json(['is_success' => true, 'data' => $data])->header('Cache-Control', 'no-store');
        } catch (IntegrationException $e) {
            return response()->json(['is_success' => false, 'error' => $e->errorCode, 'message' => $e->getMessage()], $e->status)
                ->header('Cache-Control', 'no-store');
        }
    }

    private function invalid(array $errors)
    {
        return response()->json(['is_success' => false, 'error' => 'validation_failed', 'message' => 'Invalid integration payload.', 'errors' => $errors], 422);
    }

    private function rules(string $operation): array
    {
        $role = ['name' => 'required|string|max:255', 'description' => 'nullable|string|max:2000', 'permissions' => 'present|array', 'permissions.*' => 'required|integer|min:1|distinct'];
        return match ($operation) {
            'organizations.provision' => [
                'sso_organization_id' => 'required|uuid', 'organization_name' => 'required|string|max:255',
                'organization_email' => 'nullable|email|max:255', 'owner' => 'required|array',
                'owner.sso_id' => 'required|uuid', 'owner.name' => 'required|string|max:255', 'owner.email' => 'required|email|max:255',
            ],
            'organizations.update' => ['name' => 'sometimes|required|string|max:255', 'email' => 'sometimes|nullable|email|max:255', 'address' => 'sometimes|nullable|string|max:2000'],
            'members.store' => ['sso_id' => 'required|uuid', 'name' => 'required|string|max:255', 'email' => 'required|email|max:255', 'role_id' => 'required|integer|min:1'],
            'members.role' => ['role_id' => 'required|integer|min:1'],
            'roles.store' => ['sso_role_id' => 'required|string|max:128'] + $role,
            'roles.update' => $role,
            'invitations.store' => ['sso_invitation_id' => 'required|string|max:128', 'sso_organization_id' => 'required|uuid', 'email' => 'required|email|max:255', 'role_id' => 'required|integer|min:1', 'expires_at' => 'required|date_format:Y-m-d\TH:i:s\Z'],
            default => [],
        };
    }
}
