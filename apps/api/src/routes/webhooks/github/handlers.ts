import axios from "axios";
import cuid from "cuid";
import crypto from "crypto";
import type { FastifyReply, FastifyRequest } from "fastify";
import { encrypt, errorHandler, getAPIUrl, getUIUrl, isDev, prisma } from "../../../lib/common";
import { checkContainer, removeContainer } from "../../../lib/docker";
import { scheduler } from "../../../lib/scheduler";
import { getApplicationFromDB, getApplicationFromDBWebhook } from "../../api/v1/applications/handlers";

export async function installGithub(request: FastifyRequest, reply: FastifyReply): Promise<any> {
    try {
        const { gitSourceId, installation_id } = request.query;
        const source = await prisma.gitSource.findUnique({
            where: { id: gitSourceId },
            include: { githubApp: true }
        });
        await prisma.githubApp.update({
            where: { id: source.githubAppId },
            data: { installationId: Number(installation_id) }
        });
        if (isDev) {
            return reply.redirect(`${getUIUrl()}/sources/${gitSourceId}`)
        } else {
            return reply.redirect(`/sources/${gitSourceId}`)
        }

    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }

}
export async function configureGitHubApp(request, reply) {
    try {
        const { code, state } = request.query;
        const { apiUrl } = await prisma.gitSource.findFirst({
            where: { id: state },
            include: { githubApp: true, gitlabApp: true }
        });

        const { data }: any = await axios.post(`${apiUrl}/app-manifests/${code}/conversions`);
        const { id, client_id, slug, client_secret, pem, webhook_secret } = data

        const encryptedClientSecret = encrypt(client_secret);
        const encryptedWebhookSecret = encrypt(webhook_secret);
        const encryptedPem = encrypt(pem);
        await prisma.githubApp.create({
            data: {
                appId: id,
                name: slug,
                clientId: client_id,
                clientSecret: encryptedClientSecret,
                webhookSecret: encryptedWebhookSecret,
                privateKey: encryptedPem,
                gitSource: { connect: { id: state } }
            }
        });
        if (isDev) {
            return reply.redirect(`${getUIUrl()}/sources/${state}`)
        } else {
            return reply.redirect(`/sources/${state}`)
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function gitHubEvents(request: FastifyRequest, reply: FastifyReply): Promise<any> {
    try {
        const buildId = cuid();
        const allowedGithubEvents = ['push', 'pull_request'];
        const allowedActions = ['opened', 'reopened', 'synchronize', 'closed'];
        const githubEvent = request.headers['x-github-event']?.toString().toLowerCase();
        const githubSignature = request.headers['x-hub-signature-256']?.toString().toLowerCase();
        if (!allowedGithubEvents.includes(githubEvent)) {
            throw { status: 500, message: 'Event not allowed.' }
        }
        let repository, projectId, branch;
        const body = request.body
        if (githubEvent === 'push') {
            repository = body.repository;
            projectId = repository.id;
            branch = body.ref.split('/')[2];
        } else if (githubEvent === 'pull_request') {
            repository = body.pull_request.head.repo;
            projectId = repository.id;
            branch = body.pull_request.head.ref.split('/')[2];
        }

        const applicationFound = await getApplicationFromDBWebhook(projectId, branch);
        if (applicationFound) {
            const webhookSecret = applicationFound.gitSource.githubApp.webhookSecret || null;
            //@ts-ignore
            const hmac = crypto.createHmac('sha256', webhookSecret);
            const digest = Buffer.from(
                'sha256=' + hmac.update(JSON.stringify(body)).digest('hex'),
                'utf8'
            );
            if (!isDev) {
                const checksum = Buffer.from(githubSignature, 'utf8');
                //@ts-ignore
                if (checksum.length !== digest.length || !crypto.timingSafeEqual(digest, checksum)) {
                    throw { status: 500, message: 'SHA256 checksum failed. Are you doing something fishy?' }
                };
            }


            if (githubEvent === 'push') {
                if (!applicationFound.configHash) {
                    const configHash = crypto
                        //@ts-ignore
                        .createHash('sha256')
                        .update(
                            JSON.stringify({
                                buildPack: applicationFound.buildPack,
                                port: applicationFound.port,
                                exposePort: applicationFound.exposePort,
                                installCommand: applicationFound.installCommand,
                                buildCommand: applicationFound.buildCommand,
                                startCommand: applicationFound.startCommand
                            })
                        )
                        .digest('hex');
                    await prisma.application.updateMany({
                        where: { branch, projectId },
                        data: { configHash }
                    });
                }
                await prisma.application.update({
                    where: { id: applicationFound.id },
                    data: { updatedAt: new Date() }
                });
                await prisma.build.create({
                    data: {
                        id: buildId,
                        applicationId: applicationFound.id,
                        destinationDockerId: applicationFound.destinationDocker.id,
                        gitSourceId: applicationFound.gitSource.id,
                        githubAppId: applicationFound.gitSource.githubApp?.id,
                        gitlabAppId: applicationFound.gitSource.gitlabApp?.id,
                        status: 'queued',
                        type: 'webhook_commit'
                    }
                });
                scheduler.workers.get('deployApplication').postMessage({
                    build_id: buildId,
                    type: 'webhook_commit',
                    ...applicationFound
                });

                return {
                    message: 'Queued. Thank you!'
                };
            } else if (githubEvent === 'pull_request') {
                const pullmergeRequestId = body.number;
                const pullmergeRequestAction = body.action;
                const sourceBranch = body.pull_request.head.ref;
                if (!allowedActions.includes(pullmergeRequestAction)) {
                    throw { status: 500, message: 'Action not allowed.' }
                }

                if (applicationFound.settings.previews) {
                    if (applicationFound.destinationDockerId) {
                        const isRunning = await checkContainer(
                            applicationFound.destinationDocker.engine,
                            applicationFound.id
                        );
                        if (!isRunning) {
                            throw { status: 500, message: 'Application not running.' }
                        }
                    }
                    if (
                        pullmergeRequestAction === 'opened' ||
                        pullmergeRequestAction === 'reopened' ||
                        pullmergeRequestAction === 'synchronize'
                    ) {
                        await prisma.application.update({
                            where: { id: applicationFound.id },
                            data: { updatedAt: new Date() }
                        });
                        await prisma.build.create({
                            data: {
                                id: buildId,
                                applicationId: applicationFound.id,
                                destinationDockerId: applicationFound.destinationDocker.id,
                                gitSourceId: applicationFound.gitSource.id,
                                githubAppId: applicationFound.gitSource.githubApp?.id,
                                gitlabAppId: applicationFound.gitSource.gitlabApp?.id,
                                status: 'queued',
                                type: 'webhook_pr'
                            }
                        });
                        scheduler.workers.get('deployApplication').postMessage({
                            build_id: buildId,
                            type: 'webhook_pr',
                            ...applicationFound,
                            sourceBranch,
                            pullmergeRequestId
                        });

                        return {
                            message: 'Queued. Thank you!'
                        };
                    } else if (pullmergeRequestAction === 'closed') {
                        if (applicationFound.destinationDockerId) {
                            const id = `${applicationFound.id}-${pullmergeRequestId}`;
                            const engine = applicationFound.destinationDocker.engine;
                            await removeContainer({ id, engine });
                        }
                        return {
                            message: 'Removed preview. Thank you!'
                        };
                    }
                } else {
                    throw { status: 500, message: 'Pull request previews are not enabled.' }
                }
            }
        }
        throw { status: 500, message: 'Not handled event.' }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }

}