<?php

declare(strict_types=1);

$databaseUrl = getenv('DATABASE_URL') ?: 'DATABASE_URL not set';

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stargazer Bootstrap</title>
    <style>
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            background: linear-gradient(135deg, #f4efe7 0%, #dfe8f1 100%);
            color: #18212b;
        }

        main {
            max-width: 720px;
            margin: 10vh auto;
            padding: 2.5rem;
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(24, 33, 43, 0.12);
            box-shadow: 0 24px 60px rgba(24, 33, 43, 0.12);
        }

        h1 {
            margin-top: 0;
            font-size: 2.5rem;
        }

        p,
        code {
            font-size: 1rem;
            line-height: 1.6;
        }

        code {
            word-break: break-word;
        }
    </style>
</head>
<body>
<main>
    <h1>Stargazer bootstrap is in place</h1>
    <p>
        The Docker runtime and environment defaults are ready. Symfony package installation
        is the next step once Docker Desktop is running and dependency downloads are available.
    </p>
    <p><strong>Database target:</strong> <code><?= htmlspecialchars($databaseUrl, ENT_QUOTES, 'UTF-8') ?></code></p>
</main>
</body>
</html>
