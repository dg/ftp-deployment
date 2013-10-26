<?php

return array(
    'my site' =>
        array(
            'remote' => 'ftp://user:secretpassword@ftp.example.com/directory',
            'local' => '.',
            'test' => false,
            'ignore' => '
                .git*
                project.pp[jx]
                /deployment.*
                /log
                temp/*
                !temp/.htaccess
',
            'allowdelete' => true,
            'before' =>
                array(
                    function (Ftp $ftp, Logger $logger, Deployment $deployment) {
                        $logger->log("Hello!");
                    },
                ),
            'after' => array( 'http://example.com/deployment.php?after' ),
            'purge' =>
                array(
                    'temp/cache',
                ),
            'preprocess' => true,
        )
);