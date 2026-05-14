<!DOCTYPE html>
<html lang="en">
<head>
    <base href="../">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Member - RideShare@APU</title>
    <link rel="stylesheet" href="global/main.css">
    
    <link rel="stylesheet" href="index.css">
    <style>
        :root {
            --ink: #0f1c16;
            --forest: #0f3b2e;
            --olive: #2f5a44;
            --mist: #eef6f0;
            --sand: #f4efe4;
            --sun: #f1c152;
            --clay: #cf6c4c;
            --white: #ffffff;
            --shadow: 0 18px 50px rgba(11, 28, 20, 0.16);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: var(--ink);
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, 0.18), transparent 40%),
                radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.93), transparent 45%),
                linear-gradient(160deg, #edf8f2 0%, #ffffff 100%);
            min-height: 100vh;
            overflow: hidden;
        }

        html,
        body {
            height: 100%;
        }

        .page-scroll {
            position: fixed;
            inset: 0;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .back-link {
            position: absolute;
            transform: translate(24px, 24px);
            padding: 10px 18px;
            background: rgba(15, 59, 46, 0.12);
            color: var(--forest);
            border-radius: 999px;
            border: 1px solid rgba(15, 59, 46, 0.2);
            text-decoration: none;
            font-weight: 600;
            z-index: 10;
            pointer-events: auto;
        }

        /* Give the hero content enough top space so it doesn't overlap the Back pill */
        .staff-page {
            padding-top: 100px;
        }

        .staff-hero {
            padding: 0px 8vw 72px;
            display: grid;
            gap: 32px;
            grid-template-columns: minmax(0, 1fr) minmax(0, 0.9fr);
            align-items: center;
            position: relative;
            overflow: visible;

        }

        .staff-hero::before,
        .staff-hero::after {
            content: "";
            position: absolute;
            border-radius: 999px;
            background: linear-gradient(135deg, rgba(15, 59, 46, 0.12), rgba(51, 140, 46, 0.14));
            z-index: 0;
            pointer-events: none;
        }

        .staff-hero::before {
            width: 360px;
            height: 360px;
            top: -90px;
            right: 10%;
        }

        .staff-hero::after {
            width: 240px;
            height: 240px;
            bottom: 20px;
            left: 8%;
        }

        .hero-title {
            font-size: clamp(2.2rem, 4vw, 3.4rem);
            margin: 0 0 16px;
            color: var(--forest);
            position: relative;
            z-index: 1;
        }

        .hero-subtitle {
            font-size: 1.05rem;
            line-height: 1.7;
            max-width: 520px;
            margin-bottom: 20px;
            color: var(--olive);
            position: relative;
            z-index: 1;
        }

        .tag-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            position: relative;
            z-index: 1;
        }

        .tag {
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(15, 59, 46, 0.1);
            color: var(--forest);
            font-weight: 600;
        }

        .profile-card {
            background: rgba(255, 255, 255, 0.78);
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 18px 40px rgba(11, 28, 20, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.6);
            display: grid;
            gap: 20px;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        .profile-header {
            display: grid;
            grid-template-columns: 88px 1fr;
            gap: 16px;
            align-items: center;
        }

        .profile-avatar {
            width: 88px;
            height: 88px;
            border-radius: 18px;
            background: linear-gradient(135deg, var(--forest), #0f5a40);
            color: var(--white);
            display: grid;
            place-items: center;
            font-size: 1.8rem;
            font-weight: 700;
            box-shadow: 0 12px 24px rgba(15, 59, 46, 0.2);
            overflow: hidden;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .profile-header h3 {
            margin: 0 0 6px;
            font-size: 1.4rem;
        }

        .profile-header span {
            color: var(--olive);
            font-weight: 500;
        }

        .detail-grid {
            display: grid;
            gap: 14px;
        }

        .detail-item {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 12px;
            align-items: center;
            font-size: 0.95rem;
        }

        .detail-item strong {
            color: var(--forest);
        }

        .highlight-panel {
            margin: 40px 8vw;
            background: rgba(255, 255, 255, 0.78);
            color: var(--ink);
            border-radius: 20px;
            padding: 30px;
            display: grid;
            gap: 16px;
            box-shadow: 0 18px 40px rgba(11, 28, 20, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.6);
        }

        .highlight-panel h4 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--forest);
        }

        .highlight-panel p {
            margin: 0;
            color: var(--olive);
            line-height: 1.6;
        }

        .skills-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }

        .skill-card {
            background: rgba(238, 246, 240, 0.85);
            padding: 18px;
            border-radius: 18px;
            box-shadow: 0 12px 30px rgba(15, 59, 46, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.7);
        }

        .skill-card h5 {
            margin: 0 0 10px;
            color: var(--forest);
        }

        .skill-card p {
            margin: 0;
            color: var(--olive);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .contact-row {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
        }

        .contact-pill {
            background: rgba(244, 239, 228, 0.9);
            color: var(--forest);
            border-radius: 999px;
            padding: 10px 16px;
            font-weight: 600;
            border: 1px solid rgba(15, 59, 46, 0.12);
        }

        .timeline {
            display: grid;
            gap: 16px;
        }

        .timeline-item {
            background: rgba(255, 255, 255, 0.78);
            border-left: 4px solid var(--sun);
            padding: 14px 18px;
            border-radius: 12px;
            box-shadow: 0 12px 24px rgba(15, 59, 46, 0.08);
        }

        .timeline-item strong {
            display: block;
            color: var(--forest);
            margin-bottom: 4px;
        }

        .responsibility-list {
            margin: 0;
            padding-left: 20px;
            color: var(--olive);
            line-height: 1.7;
        }

        .responsibility-list li {
            margin-bottom: 8px;
        }

        @media (max-width: 900px) {
            .staff-hero {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .staff-page {
                padding-top: 100px;
            }

            .staff-hero {
                padding: 0px 6vw 30px;
            }

            .detail-item {
                grid-template-columns: 1fr;
                gap: 4px;
            }

            .contact-row {
                display: flex;
                flex-wrap: wrap;
                gap: 14px;
            }

            .contact-pill {
                flex: 1 1 240px;
                padding: 10px 10px;
                
            }

        }
    </style>
</head>
<body>
    <div class="page-scroll">
        <a class="back-link" href="index.php">Back</a>
        <main class="staff-page">
        <section class="staff-hero">
            <div>
                <h1 class="hero-title">Low Sook Yao</h1>
                <p class="hero-subtitle">Diploma in ICT (DI) Student at APU</p>
                <div class="tag-row">
                    <span class="tag">APU</span>
                    <span class="tag">Diploma in ICT</span>
                    <span class="tag">Programming</span>
                    <span class="tag">Web Development</span>
                </div>
            </div>

            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <img src="staff/LOWSOOKYAO.jpeg" alt="Low Sook Yao portrait">
                    </div>
                    <div>
                        <h3>Low Sook Yao</h3>
                        <span>Diploma in ICT (DI)</span>
                    </div>
                </div>
                <div class="detail-grid">
                    <div class="detail-item">
                        <strong>Education</strong>
                        <span>Asia Pacific University (APU)</span>
                    </div>
                    <div class="detail-item">
                        <strong>Program</strong>
                        <span>Diploma in ICT (DI)</span>
                    </div>
                    <div class="detail-item">
                        <strong>Email</strong>
                        <span>susanlow135@gmail.com</span>
                    </div>
                    <div class="detail-item">
                        <strong>Phone</strong>
                        <span>01111222159</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="highlight-panel">
            <h4>About Me</h4>
            <p>
                I am currently pursuing a Diploma in Information &amp; Communication Technology (ICT) at
                Asia Pacific University (APU). My studies cover both theoretical and practical aspects of
                computing, including programming, data management, analytics, and system development.
                Key academic areas include Enterprise Data Analytics &amp; Visualization (EDAV),
                Object-Oriented Programming (OOP), Database Management (DBM), and Introduction to Data Analytics (IDA).
            </p>
        </section>

        <section class="highlight-panel">
            <h4>Education</h4>
            <ul class="responsibility-list">
                <li>Asia Pacific University (APU), Diploma in ICT (DI).</li>
            </ul>
        </section>

        <section class="highlight-panel">
            <h4>Skills</h4>
            <div class="skills-grid">
                <div class="skill-card">
                    <h5>Programming Languages</h5>
                    <p>Python, Java (OOP concepts).</p>
                </div>
                <div class="skill-card">
                    <h5>Database</h5>
                    <p>MySQL (DBM concepts).</p>
                </div>
                <div class="skill-card">
                    <h5>Data Analytics &amp; Visualization</h5>
                    <p>RapidMiner, Excel, EDAV, IDA.</p>
                </div>
                <div class="skill-card">
                    <h5>Web Development</h5>
                    <p>HTML, CSS, basic JavaScript.</p>
                </div>
                <div class="skill-card">
                    <h5>Other Skills</h5>
                    <p>Problem-solving, analytical thinking, teamwork, basic system design.</p>
                </div>
            </div>
        </section>

        <section class="highlight-panel">
            <h4>Project Experiences</h4>
            <div class="skills-grid">
                <div class="skill-card">
                    <h5>Tuition Centre Management System (Python)</h5>
                    <p>
                        Developed a menu-driven system using Python and text-file storage to manage students,
                        tutors, classes, subject requests, and payments while applying OOP principles.
                    </p>
                </div>
                <div class="skill-card">
                    <h5>Data Analysis &amp; Visualization (RapidMiner &amp; Excel)</h5>
                    <p>
                        Performed data cleaning, transformation, aggregation, and visualization using RapidMiner
                        and Excel to identify patterns and support data-driven decision-making.
                    </p>
                </div>
                <div class="skill-card">
                    <h5>Basic Web Development Project</h5>
                    <p>
                        Created a simple website using HTML, CSS, and JavaScript with a focus on layout structure,
                        usability, and responsive design.
                    </p>
                </div>
            </div>
        </section>

        <section class="highlight-panel">
            <h4>Contact</h4>
            <div class="contact-row">
                <span class="contact-pill">Email: susanlow135@gmail.com</span>
                <span class="contact-pill">Contact: 011-11222159</span>
            </div>
        </section>
        </main>
    </div>

    
    <script src="global/main.js"></script>
</body>
</html>
