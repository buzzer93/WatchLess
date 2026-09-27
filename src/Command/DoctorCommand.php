<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'watchless:doctor', description: 'Vérifie la configuration locale sans appel API ni divulgation de clé.')]
final class DoctorCommand extends Command
{
    public function __construct(private Connection $connection,
        #[Autowire('%env(YTDLP_BINARY)%')] private string $ytDlp,
        #[Autowire('%env(FFMPEG_BINARY)%')] private string $ffmpeg,
        #[Autowire('%env(WHISPER_BINARY)%')] private string $whisper,
        #[Autowire('%env(TRANSCRIPTION_PROVIDER)%')] private string $transcriptionProvider,
        #[Autowire('%env(OPENAI_API_KEY)%')] private string $openaiKey,
        #[Autowire('%env(LLM_BASE_URL)%')] private string $llmUrl,
        #[Autowire('%env(LLM_API_KEY)%')] private string $llmKey,
        #[Autowire('%env(LLM_MODEL)%')] private string $model)
    { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        $ok = true;
        $check = function (string $name, bool $ready, string $hint = '') use (&$rows, &$ok): void {
            $rows[] = [$name, $ready ? 'OK' : 'À configurer', $ready ? '' : $hint];
            $ok = $ok && $ready;
        };
        foreach ([[$this->ytDlp, '--version', 'yt-dlp'], [$this->ffmpeg, '-version', 'FFmpeg']] as [$binary, $flag, $label]) {
            try { $process = new Process([$binary, $flag], timeout: 10); $process->run(); $ready = $process->isSuccessful(); }
            catch (\Throwable) { $ready = false; }
            $check($label, $ready, 'Installer l’exécutable et vérifier son chemin dans .env.local.');
        }
        if ($this->transcriptionProvider === 'local') {
            try { $process = new Process([$this->whisper, '--help'], timeout: 30); $process->run(); $ready = $process->isSuccessful(); }
            catch (\Throwable) { $ready = false; }
            $check('Whisper local', $ready, 'Installer openai-whisper ou choisir TRANSCRIPTION_PROVIDER=openai.');
        } else {
            $check('Transcription API', $this->transcriptionProvider === 'openai' && $this->openaiKey !== '', 'Définir TRANSCRIPTION_PROVIDER et OPENAI_API_KEY.');
        }
        $check('Modèle LLM', $this->model !== '', 'Définir LLM_MODEL selon votre serveur ou compte API.');
        $check('Clé LLM', parse_url($this->llmUrl, PHP_URL_HOST) !== 'api.openai.com' || $this->llmKey !== '', 'Définir LLM_API_KEY.');
        try {
            $tables = $this->connection->createSchemaManager()->listTableNames();
            $ready = in_array('video', $tables, true) && in_array('messenger_messages', $tables, true);
        } catch (\Throwable) { $ready = false; }
        $check('SQLite et file Messenger', $ready, 'Exécuter doctrine:migrations:migrate.');
        $io->table(['Composant', 'État', 'Action'], $rows);
        $io->note('Démarrer aussi le worker : php bin/console messenger:consume async -v. Les accès YouTube et API ne sont pas testés par cette commande.');
        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
