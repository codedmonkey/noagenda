<?php

namespace App\Command;

use App\Entity\BatSignal;
use App\Entity\Episode;
use App\Repository\BatSignalRepository;
use App\Repository\EpisodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

class MatchRecordingTimeCommand extends Command
{
    protected static $defaultName = 'app:match-recording-time';

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var BatSignalRepository
     */
    private $batSignalRepository;

    /**
     * @var EpisodeRepository
     */
    private $episodeRepository;

    /**
     * @var string
     */
    private $storagePath;

    public function __construct(
        ?string $name = null,
        EntityManagerInterface $entityManager,
        BatSignalRepository $batSignalRepository,
        EpisodeRepository $episodeRepository,
        string $storagePath
    )
    {
        parent::__construct($name);

        $this->entityManager = $entityManager;
        $this->batSignalRepository = $batSignalRepository;
        $this->episodeRepository = $episodeRepository;
        $this->storagePath = $storagePath;
    }

    protected function configure()
    {
        $this
            ->setDescription('Finds the show\'s original recording time by matching livestream recordings')
            ->addArgument('episode', InputArgument::REQUIRED, 'The episode code')
            ->addOption('save', null, InputOption::VALUE_NONE, 'Save crawling results in the database')
            ->addOption('skip-split', null, InputOption::VALUE_NONE, 'Skip splitting of the episode file')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $save = $input->getOption('save');
        $skipSplitting = $input->getOption('skip-split');

        $code = $input->getArgument('episode');
        $episode = $this->episodeRepository->findOneBy(['code' => $code]);

        if ($episode === null) {
            $io->error(sprintf('Unknown episode "%s".', $code));

            return;
        }

        if (!$episode->getDuration()) {
            $io->error('Unable to match chat messages for an episode without a recording duration.');

            return;
        }

        $signal = $this->batSignalRepository->findOneByCode($episode->getCode());

        if ($signal === null) {
            $io->error(sprintf('Bat signal for episode "%s" was not found.', $code));

            return;
        }

        $liveFiles = $this->getLivestreamRecordings($signal);

        if ($liveFiles->count() === 0) {
            $io->error('No livestream recordings found that match the given bat signal.');

            return;
        }

        if (!$skipSplitting && !$this->splitRecording($input, $output, $episode)) {
            return;
        }

        $recordedAt = $this->matchRecordings($input, $output, $signal);

        if (!$recordedAt) {
            $io->error(sprintf('Recording time for episode "%s" could not be matched.', $code));

            return;
        }

        $recordedAt = new \DateTime($recordedAt);
        $io->text(sprintf('Episode was recorded at: %s.', $recordedAt->format('Y-m-d H:i:s')));

        $episode->setRecordedAt($recordedAt);

        $this->entityManager->persist($episode);

        if ($save) {
            $this->entityManager->flush();

            $io->success('Recording time was saved.');
        }
        else {
            $io->note('The crawling results have not been saved. Pass the `--save` option to save the results in the database.');
        }
    }

    protected function matchRecordings(InputInterface $input, OutputInterface $output, BatSignal $signal)
    {
        $io = new SymfonyStyle($input, $output);

        $io->text('Matching recordings ...');

        $processHelper = $this->getHelper('process');

        $sourcePath = sprintf('%s/episode_parts', $this->storagePath);

        $sourceFiles = (new Finder)
            ->files()
            ->in($sourcePath)
            ->name(sprintf('%s_*.mp3', $signal->getCode()))
        ;

        $liveFiles = $this->getLivestreamRecordings($signal);

        if ($liveFiles->count() === 0) {
            return false;
        }

        $recordingMatrix = [];

        $amount = count($liveFiles) * count($sourceFiles);
        $progressBar = new ProgressBar($output, $amount);
        $progressBar->start();

        /** @var \SplFileInfo $liveFile */
        foreach ($liveFiles as $liveFile) {
            $timestamp = substr($liveFile->getFilename(), strlen('recording_'), 14);

            /** @var \SplFileInfo $sourceFile */
            foreach ($sourceFiles as $sourceFile) {
                $recordedAt = new \DateTime($timestamp);

                preg_match("/_(\d+)./", $sourceFile->getFilename(), $matches);
                list(, $offset) = $matches;

                if ($io->isVeryVerbose()) {
                    $io->text(sprintf('Matching livestream recording "%s" to episode offset %s-%s.', $timestamp, $offset, $offset + 600));
                }

                $command = [
                    'audio-offset-finder',
                    '--find-offset-of',
                    $liveFile->getPathname(),
                    '--within',
                    $sourceFile->getPathname(),
                ];

                $process = new Process($command);
                $processHelper->run($output, $process);

                preg_match("/Offset: (\d+)/", $process->getOutput(), $matches);
                list(, $matchedOffset) = $matches;
                preg_match("/score: (\d+)/", $process->getOutput(), $matches);
                list(, $matchedScore) = $matches;

                $recordingOffset = $offset + $matchedOffset;
                $episodeRecordedAt = $recordedAt->sub(new \DateInterval('PT' . $recordingOffset . 'S'));

                $episodeRecordedAtKey = $episodeRecordedAt->format('YmdHis');

                if (!isset($recordingMatrix[$episodeRecordedAtKey])) {
                    $recordingMatrix[$episodeRecordedAtKey] = [];
                }

                $recordingMatrix[$episodeRecordedAtKey][] = $matchedScore;

                if ($io->isVerbose()) {
                    $io->text(sprintf('Matched recording for time "%s" with a score of %s.', $episodeRecordedAt->format('Y-m-d H:i:s'), $matchedScore));
                }

                $io->newLine();

                $progressBar->advance();
                $output->write("\n");
            }
        }

        // Optimize recording matrix
        ksort($recordingMatrix);

        foreach ($recordingMatrix as $key => $scores) {
            foreach ($recordingMatrix as $matchKey => $matchScores) {
                // Found similar timestamps
                if ($matchKey > $key && $matchKey < ($key + 5)) {
                    // The latter has more scores
                    if (count($matchScores) > count($scores)) {
                        unset($recordingMatrix[$key]);
                        $recordingMatrix[$matchKey] = array_merge($scores, $matchScores);

                        continue 2;
                    }

                    // The first one has more or equal scores
                    unset($recordingMatrix[$matchKey]);
                    $recordingMatrix[$key] = array_merge($scores, $matchScores);
                }
            }
        }

        // Sort by top match
        uasort($recordingMatrix, function($a, $b) {
            // A has more matches
            if (count($a) > count($b)) {
                return -1;
            }

            // B has more matches
            if (count($a) < count($b)) {
                return 1;
            }

            $averageA = array_sum($a) / count($a);
            $averageB = array_sum($b) / count($b);

            if ($averageA == $averageB) {
                return 0;
            }

            return ($averageA > $averageB) ? -1 : 1;
        });

        if ($io->isVerbose()) {
            $io->note('Recording matrix dump:');

            $listing = [];
            foreach ($recordingMatrix as $key => $matches) {
                $listing[] = sprintf('%s: %s', $key, implode(', ', $matches));
            }

            $io->listing($listing);
        }

        return array_keys($recordingMatrix)[0];
    }

    protected function splitRecording(InputInterface $input, OutputInterface $output, Episode $episode)
    {
        $io = new SymfonyStyle($input, $output);

        // Clean up directory
        $sourcePath = sprintf('%s/episode_parts', $this->storagePath);

        $sourceFiles = (new Finder)
            ->files()
            ->in($sourcePath)
        ;

        foreach ($sourceFiles as $sourceFile) {
            unlink($sourceFile);
        }

        $io->text('Splitting episode ...');

        $sourcePath = sprintf('%s/episode_recordings/%s.mp3', $this->storagePath, $episode->getCode());
        $targetPathPrefix = sprintf('%s/episode_parts/%s_', $this->storagePath, $episode->getCode());

        $command = [
            'bin/scripts/split-recording.bash',
            $sourcePath,
            $targetPathPrefix,
        ];

        if ($output->isVerbose()) {
            $io->text('Executing command: ' . implode(' ', $command));
        }

        $process = new Process($command);
        $process->setTimeout(null);
        $returnCode = $process->run();

        if ($returnCode > 0) {
            $io->error($output->isVerbose() ? $process->getErrorOutput() : 'An error occurred while splitting the recording.');

            return false;
        }

        return true;
    }

    protected function getLivestreamRecordings(BatSignal $signal): Finder
    {
        $livePath = sprintf('%s/livestream_recordings', $this->storagePath);

        return (new Finder)
            ->files()
            ->in($livePath)
            ->name('recording_*.asf')
            ->filter(function(\SplFileInfo $file) use ($signal) {
                $timestamp = substr($file->getFilename(), strlen('recording_'), 14);
                $recordedAt = new \DateTime($timestamp);

                // Filter out files that are recorded before the bat signal
                if ($recordedAt < $signal->getDeployedAt()) {
                    return false;
                }

                $recordedBefore = (new \DateTime($signal->getDeployedAt()->format('YmdHis')))->add(new \DateInterval('PT3H'));

                // Filter out files that are recorded more than 3 hours after the bat signal
                if ($recordedAt > $recordedBefore) {
                    return false;
                }

                return true;
            })
            ->sortByName()
        ;
    }
}
