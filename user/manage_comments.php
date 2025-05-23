<?php
session_start();
include '../Partials/db_connection.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['username'];

// Verify if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Pagination setup
$limit = 10; // Number of items per page
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$page = ($page <= 0) ? 1 : $page; // If page is less than or equal to 0, reset to 1
$offset = ($page - 1) * $limit; // Offset for SQL query

// Search functionality
$search_query = '';
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = mysqli_real_escape_string($conn, $_GET['search']);
    $search_query = "AND (c.comment LIKE '%$search%' OR t.thread_user_name LIKE '%$search%' OR t.thread_title LIKE '%$search%' OR r.reply_text LIKE '%$search%')";
}

// Query to get both comments and replies
$combined_query = "
    (SELECT 
        'comment' AS type,
        c.comment_id AS id,
        c.comment AS content,
        c.comment_time AS time,
        t.thread_id,
        t.thread_title,
        t.thread_user_name,
        NULL AS parent_comment,
        NULL AS parent_comment_id
    FROM comments c
    INNER JOIN thread t ON c.thread_comment_id = t.thread_id
    WHERE c.user_name = '$user_name' $search_query)
    
    UNION ALL
    
    (SELECT 
        'reply' AS type,
        r.reply_id AS id,
        r.reply_text AS content,
        r.reply_time AS time,
        t.thread_id,
        t.thread_title,
        t.thread_user_name,
        c.comment AS parent_comment,
        c.comment_id AS parent_comment_id
    FROM replies r
    INNER JOIN comments c ON r.comment_id = c.comment_id
    INNER JOIN thread t ON c.thread_comment_id = t.thread_id
    WHERE r.user_name = '$user_name' $search_query)
    
    ORDER BY time DESC
    LIMIT $offset, $limit";

$combined_result = mysqli_query($conn, $combined_query);

// Get total number of items for pagination
$total_query = "
    SELECT COUNT(*) FROM (
        (SELECT c.comment_id
        FROM comments c
        INNER JOIN thread t ON c.thread_comment_id = t.thread_id
        WHERE c.user_name = '$user_name' $search_query)
        
        UNION ALL
        
        (SELECT r.reply_id
        FROM replies r
        INNER JOIN comments c ON r.comment_id = c.comment_id
        INNER JOIN thread t ON c.thread_comment_id = t.thread_id
        WHERE r.user_name = '$user_name' $search_query)
    ) AS combined";

$total_result = $conn->query($total_query);
$total_items = $total_result->fetch_row()[0];
$total_pages = ceil($total_items / $limit); // Calculate total pages

// Handle comment deletion
if (isset($_GET['delete_comment_id'])) {
    $delete_id = intval($_GET['delete_comment_id']);
    $delete_query = "DELETE FROM comments WHERE comment_id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $stmt->close();
    header('Location: manage_comments.php' . (isset($_GET['search']) ? '?search=' . urlencode($_GET['search']) : ''));
    exit();
}

// Handle comment editing
if (isset($_POST['edit_comment'])) {
    $comment_id = $_POST['comment_id'];

    // Sanitize input (remove line breaks)
    $new_comment = $_POST['comment']; // Basic: no sanitization beyond escaping
    $new_comment = str_replace(array("\r", "\n"), ' ', $new_comment); // Replace line breaks with space

    // Escape for SQL (Do this right before the query)
    $new_comment = mysqli_real_escape_string($conn, $new_comment);

    $update_query = "UPDATE comments SET comment = ? WHERE comment_id = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $new_comment, $comment_id);
    $stmt->execute();
    $stmt->close();
    header('Location: manage_comments.php' . (isset($_GET['search']) ? '?search=' . urlencode($_GET['search']) : ''));
    exit();
}

// Handle reply deletion
if (isset($_GET['delete_reply_id'])) {
    $delete_reply_id = intval($_GET['delete_reply_id']);
    $delete_reply_query = "DELETE FROM replies WHERE reply_id = ?";
    $stmt = $conn->prepare($delete_reply_query);
    $stmt->bind_param("i", $delete_reply_id);
    $stmt->execute();
    $stmt->close();
    header('Location: manage_comments.php' . (isset($_GET['search']) ? '?search=' . urlencode($_GET['search']) : ''));
    exit();
}

// Handle reply editing
if (isset($_POST['edit_reply'])) {
    $reply_id = intval($_POST['reply_id']);
    $reply_text = trim($_POST['reply_text']);
    $reply_text = mysqli_real_escape_string($conn, $reply_text);
    $update_reply_query = "UPDATE replies SET reply_text = ? WHERE reply_id = ?";
    $stmt = $conn->prepare($update_reply_query);
    $stmt->bind_param("si", $reply_text, $reply_id);
    $stmt->execute();
    $stmt->close();
    header('Location: manage_comments.php' . (isset($_GET['search']) ? '?search=' . urlencode($_GET['search']) : ''));
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý bình luận và trả lời</title>
    <link rel="icon" type="image/jpg" href="/Forum_website/images/favicon1.jpg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #f4f4f9;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .container1 {
            margin-top: 80px;
        }

        .btn {
            padding: 8px 15px;
        }

        .search-bar {
            margin-bottom: 20px;
        }

        .table-responsive {
            margin-bottom: 20px;
        }

        /* To ensure the page works well on small screens */
        @media (max-width: 768px) {
            .table th,
            .table td {
                padding: 10px;
            }

            .btn {
                padding: 6px 12px;
            }

            .search-bar input {
                width: 100%;
            }
        }

        /* Style for the scrollable td */
        .table-responsive tbody td {
            max-height: 150px;
            /* Adjust max-height as needed */
            overflow-y: auto;
            display: block;
            /* Make td a block-level element */
            padding: 10px;
            word-break: break-word;
            /* Allow long words to break */
            white-space: normal;
            /* Allow text to wrap normally */
        }

        .table th {
            text-align: center;
        }

        .table td {
            white-space: normal;
            word-break: break-word;
        }
        .footer{
            margin-top: auto;
        }
        .comment-type {
            background-color: #e7f3ff;
            border-radius: 4px;
            padding: 2px 8px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .reply-type {
            background-color: #e7fff3;
            border-radius: 4px;
            padding: 2px 8px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .parent-comment {
            background-color: #f8f9fa;
            border-left: 3px solid #6c757d;
            padding: 8px;
            margin-top: 5px;
            font-style: italic;
            font-size: 0.9rem;
        }
    </style>
</head>

<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="user_profile.php">Bảng điều khiển người dùng</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="user_profile.php">Trở về bảng điều khiển</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../Partials/_handle_logout.php">Đăng xuất</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container container1">
        <button class="button rounded py-2 px-3 mt-2 text-white bg-dark border-0"> 
            <a class="text-white text-decoration-none" href="user_profile.php">Trở về bảng điều khiển</a>
        </button>
    </div>

    <!-- Main Content -->
    <div class="container mt-3">
        <h2>Quản lý bình luận và trả lời</h2>

        <!-- Search Form -->
        <form class="search-bar" method="GET" action="manage_comments.php">
            <div class="input-group">
                <input type="text" name="search" class="form-control" placeholder="Search by comment, reply, or question"
                    value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                <button type="submit" class="btn btn-primary">Search</button>
            </div>
        </form>

        <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
            <a href="manage_comments.php" class="btn btn-secondary mb-3">Quay lại tất cả các mục</a>
        <?php endif; ?>

        <!-- Combined Table of Comments and Replies -->
        <div class="table-responsive">
            <table class="table table-bordered table-hover text-center">
                <tbody>
                    <?php
                    $serial = $offset + 1; // Serial number starts from 1 on each page
                    while ($item = $combined_result->fetch_assoc()): 
                        $is_comment = ($item['type'] == 'comment');
                    ?>
                        <tr>
                            <td class="bg-secondary fw-bold text-center"><?= $serial++; ?></td>
                            <td class="bg-secondary-subtle px-3 w-auto text-start" style="min-width: 150px;">
                                <span class="fw-bold text-primary"> Câu hỏi được đăng bởi: </span>
                                <?= htmlspecialchars($item['thread_user_name']); ?>
                                <br>
                                <span class="<?= $is_comment ? 'comment-type' : 'reply-type' ?>">
                                    <?= $is_comment ? 'Comment' : 'Reply' ?>
                                </span>
                            </td>
                            <td class="bg-light px-3 text-start text-wrap" style="min-width: 180px;">
                                <span class="fw-bold text-success"> Câu hỏi: </span>
                                <?= htmlspecialchars($item['thread_title']); ?>
                            </td>
                            <td class="bg-warning-subtle px-3 text-start text-wrap" style="min-width: 200px;">
                                <?php if (!$is_comment): ?>
                                    <span class="fw-bold text-danger"> Câu trả lời của bạn: </span>
                                    <?= nl2br(htmlspecialchars($item['content'])); ?>
                                    <?php if ($item['parent_comment']): ?>
                                        <div class="parent-comment">
                                            <small>In response to: <?= htmlspecialchars($item['parent_comment']); ?></small>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="fw-bold text-danger"> Bình luận của bạn: </span>
                                    <?= nl2br(htmlspecialchars($item['content'])); ?>
                                <?php endif; ?>
                            </td>
                            <td class="bg-light text-muted text-start" style="min-width: 120px;">
                                <?= htmlspecialchars($item['time']); ?>
                            </td>
                            <td class="w-auto text-start" style="min-width: 150px;">
                                <?php if ($is_comment): ?>
                                    <!-- Edit Comment Button -->
                                    <button class="btn btn-warning btn-sm mt-1 px-3" data-bs-toggle="modal"
                                        data-bs-target="#editCommentModal<?= $item['id']; ?>">
                                        ✏️ Chỉnh sửa
                                    </button>
                                    <!-- Delete Comment Button -->
                                    <a href="manage_comments.php?delete_comment_id=<?= $item['id']; ?><?= (isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''); ?>"
                                        class="btn btn-danger btn-sm mt-1 px-3"
                                        onclick="return confirm('Are you sure you want to delete this comment?');">
                                        ❌ Xóa
                                    </a>
                                <?php else: ?>
                                    <!-- Edit Reply Button -->
                                    <button class="btn btn-warning btn-sm mt-1 px-3" data-bs-toggle="modal"
                                        data-bs-target="#editReplyModal<?= $item['id']; ?>">
                                        ✏️ Chỉnh sửa
                                    </button>
                                    <!-- Delete Reply Button -->
                                    <a href="manage_comments.php?delete_reply_id=<?= $item['id']; ?><?= (isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''); ?>"
                                        class="btn btn-danger btn-sm mt-1 px-3"
                                        onclick="return confirm('Are you sure you want to delete this reply?');">
                                        ❌ Xóa
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <?php if ($is_comment): ?>
                            <!-- Edit Comment Modal -->
                            <div class="modal fade" id="editCommentModal<?= $item['id']; ?>" tabindex="-1"
                                aria-labelledby="editCommentModalLabel" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="editCommentModalLabel">Edit Comment</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <form
                                                action="manage_comments.php<?= (isset($_GET['search']) ? '?search=' . urlencode($_GET['search']) : '') ?>"
                                                method="POST">
                                                <div class="mb-3">
                                                    <label for="comment" class="form-label">Comment</label>
                                                    <textarea class="form-control" name="comment" id="comment"
                                                        rows="4"><?= htmlspecialchars($item['content']); ?></textarea>
                                                </div>
                                                <input type="hidden" name="comment_id" value="<?= $item['id']; ?>">
                                                <button type="submit" name="edit_comment" class="btn btn-primary">Save
                                                    Changes</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Edit Reply Modal -->
                            <div class="modal fade" id="editReplyModal<?= $item['id']; ?>" tabindex="-1"
                                aria-labelledby="editReplyModalLabel<?= $item['id']; ?>" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form action="manage_comments.php<?= (isset($_GET['search']) ? '?search=' . urlencode($_GET['search']) : '') ?>" method="POST">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="editReplyModalLabel<?= $item['id']; ?>">Edit Reply</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                    aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label for="reply_text_<?= $item['id']; ?>" class="form-label">Reply</label>
                                                    <textarea class="form-control" name="reply_text" id="reply_text_<?= $item['id']; ?>" rows="3"><?= htmlspecialchars($item['content']); ?></textarea>
                                                </div>
                                                <input type="hidden" name="reply_id" value="<?= $item['id']; ?>">
                                                <button type="submit" name="edit_reply" class="btn btn-primary">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <nav>
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link"
                        href="manage_comments.php?page=<?= max($page - 1, 1); ?><?= (isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''); ?>">Previous</a>
                </li>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : ''; ?>">
                        <a class="page-link"
                            href="manage_comments.php?page=<?= $i; ?><?= (isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''); ?>"><?= $i; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : ''; ?>">
                    <a class="page-link"
                        href="manage_comments.php?page=<?= min($page + 1, $total_pages); ?><?= (isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''); ?>">Next</a>
                </li>
            </ul>
        </nav>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <div class="footer">
        <?php include '../Partials/_footer.php'; ?>
    </div>
</body>

</html>