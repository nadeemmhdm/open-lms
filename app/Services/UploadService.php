<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Config;
use OpenLMS\Core\Database;

final class UploadService
{
    private const MIME_BY_EXT = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/x-zip'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'mp4'  => ['video/mp4'],
        'zip'  => ['application/zip', 'application/x-zip-compressed', 'application/x-zip'],
    ];

    public static function storeLessonMaterial(int $lessonId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [false, self::uploadError((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE))];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $original = self::safeOriginalName((string) ($file['name'] ?? 'file'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $max = max(1024 * 1024, (int) Config::get('uploads.max_bytes', 10 * 1024 * 1024));

        if (!isset(self::MIME_BY_EXT[$ext])) {
            return [false, 'Unsupported file type. Allowed: PDF, DOC, DOCX, PPT, PPTX, JPG, PNG, WebP, MP4 and ZIP.'];
        }
        if ($size < 1 || $size > $max) {
            return [false, 'File size is outside the configured upload limit (' . self::humanBytes($max) . ').'];
        }
        if (!is_uploaded_file($tmp)) {
            return [false, 'Upload source validation failed.'];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!in_array($mime, self::MIME_BY_EXT[$ext], true)) {
            return [false, 'The file content does not match its extension.'];
        }
        if (!self::signatureMatches($tmp, $ext)) {
            return [false, 'The file signature is invalid for this file type.'];
        }

        $relativeDir = 'materials/' . $lessonId;
        $absoluteDir = app_root() . '/storage/uploads/' . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) {
            return [false, 'Unable to create private upload directory.'];
        }

        $stored = bin2hex(random_bytes(24)) . '.dat';
        $relative = $relativeDir . '/' . $stored;
        $absolute = app_root() . '/storage/uploads/' . $relative;
        if (!move_uploaded_file($tmp, $absolute)) {
            return [false, 'Unable to store uploaded file.'];
        }
        @chmod($absolute, 0640);

        try {
            $stmt = Database::connection()->prepare('INSERT INTO learning_materials (lesson_id, original_name, stored_name, mime_type, size_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$lessonId, $original, $relative, $mime, $size, gmdate('Y-m-d H:i:s')]);
            return [true, (int) Database::connection()->lastInsertId()];
        } catch (\Throwable $e) {
            @unlink($absolute);
            throw $e;
        }
    }

    public static function storeProjectSubmissionFile(int $submissionId, array $file, array $allowedExtensions, int $maxBytes): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [false, self::uploadError((int)($file['error'] ?? UPLOAD_ERR_NO_FILE))];
        }
        $tmp=(string)($file['tmp_name']??''); $size=(int)($file['size']??0); $original=self::safeOriginalName((string)($file['name']??'file'));
        $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION)); $allowed=array_values(array_intersect(array_keys(self::MIME_BY_EXT),array_map('strtolower',$allowedExtensions)));
        if(!in_array($ext,$allowed,true)) return [false,'This file type is not allowed for the project.'];
        $limit=max(1024*1024,$maxBytes); if($size<1||$size>$limit)return [false,'File size is outside the project upload limit ('.self::humanBytes($limit).').'];
        if(!is_uploaded_file($tmp))return [false,'Upload source validation failed.'];
        $finfo=new \finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);if(!in_array($mime,self::MIME_BY_EXT[$ext],true))return [false,'The file content does not match its extension.'];
        if(!self::signatureMatches($tmp,$ext))return [false,'The file signature is invalid for this file type.'];
        $relativeDir='projects/'.$submissionId;$absoluteDir=app_root().'/storage/uploads/'.$relativeDir;if(!is_dir($absoluteDir)&&!mkdir($absoluteDir,0750,true)&&!is_dir($absoluteDir))return [false,'Unable to create private upload directory.'];
        $stored=bin2hex(random_bytes(24)).'.dat';$relative=$relativeDir.'/'.$stored;$absolute=app_root().'/storage/uploads/'.$relative;if(!move_uploaded_file($tmp,$absolute))return [false,'Unable to store uploaded file.'];@chmod($absolute,0640);
        try{Database::connection()->prepare('INSERT INTO submission_files (submission_id,original_name,stored_name,mime_type,size_bytes,created_at) VALUES (?,?,?,?,?,?)')->execute([$submissionId,$original,$relative,$mime,$size,gmdate('Y-m-d H:i:s')]);return [true,(int)Database::connection()->lastInsertId()];}
        catch(\Throwable $e){@unlink($absolute);throw $e;}
    }

    public static function deleteSubmissionFile(array $file): void
    {
        $absolute=self::resolveStoredPath((string)($file['stored_name']??''));
        Database::connection()->prepare('DELETE FROM submission_files WHERE id=?')->execute([(int)$file['id']]);
        if($absolute&&is_file($absolute))@unlink($absolute);
    }

    public static function deleteMaterial(array $material): void
    {
        $relative = (string) ($material['stored_name'] ?? '');
        $absolute = self::resolveStoredPath($relative);
        Database::connection()->prepare('DELETE FROM learning_materials WHERE id = ?')->execute([(int) $material['id']]);
        if ($absolute && is_file($absolute)) {
            @unlink($absolute);
        }
    }

    public static function resolveStoredPath(string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, "\\")) {
            return null;
        }
        $base = realpath(app_root() . '/storage/uploads');
        $candidate = realpath(app_root() . '/storage/uploads/' . $relative);
        if (!$base || !$candidate || !str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $candidate;
    }

    private static function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?: 'file';
        $name = preg_replace('/[^\pL\pN._()\- ]+/u', '_', $name) ?: 'file';
        return mb_substr(trim($name), 0, 180);
    }

    private static function signatureMatches(string $file, string $ext): bool
    {
        $h = fopen($file, 'rb');
        if (!$h) return false;
        $head = fread($h, 16) ?: '';
        fclose($h);
        $hex = strtoupper(bin2hex($head));

        return match ($ext) {
            'pdf' => str_starts_with($head, '%PDF-'),
            'jpg', 'jpeg' => str_starts_with($hex, 'FFD8FF'),
            'png' => str_starts_with($hex, '89504E470D0A1A0A'),
            'webp' => strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP',
            'mp4' => strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp',
            'doc', 'ppt' => str_starts_with($hex, 'D0CF11E0A1B11AE1'),
            'docx', 'pptx', 'zip' => str_starts_with($hex, '504B0304') || str_starts_with($hex, '504B0506') || str_starts_with($hex, '504B0708'),
            default => false,
        };
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL => 'The file upload was interrupted.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload temporary directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
            default => 'The upload failed.',
        };
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? round($bytes / 1024 / 1024, 1) . ' MB' : round($bytes / 1024, 1) . ' KB';
    }
}
