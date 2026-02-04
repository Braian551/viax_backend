"""
Verificación Biométrica Facial - Optimizado
============================================

Comparación matemática rápida usando distancia euclidiana.
Solo devuelve plantillas (encodings), NO guarda fotos.

Complejidad: O(n * 128) donde n = plantillas bloqueadas
"""

import sys
import json
import os
import time
import random
import math

# Intentar importar face_recognition
try:
    import face_recognition
    import numpy as np
    FACE_RECOGNITION_AVAILABLE = True
except ImportError:
    FACE_RECOGNITION_AVAILABLE = False


def euclidean_distance(enc1: list, enc2: list) -> float:
    """Distancia euclidiana O(128) - muy rápida"""
    if len(enc1) != len(enc2):
        return float('inf')
    return math.sqrt(sum((a - b) ** 2 for a, b in zip(enc1, enc2)))


def get_face_encoding(image_path: str) -> list | None:
    """Extrae encoding facial (128 floats)"""
    if not FACE_RECOGNITION_AVAILABLE:
        return None
    try:
        image = face_recognition.load_image_file(image_path)
        encodings = face_recognition.face_encodings(image)
        return encodings[0].tolist() if encodings else None
    except Exception:
        return None


def check_against_blocked(encoding: list, blocked_list: list, threshold: float = 0.5) -> tuple[bool, str | None]:
    """
    Verifica si el encoding coincide con algún bloqueado.
    Threshold 0.5 = muy estricto (evita falsos positivos)
    """
    if not encoding or not blocked_list:
        return False, None
    
    for blocked in blocked_list:
        try:
            blocked_enc = blocked if isinstance(blocked, list) else json.loads(blocked)
            distance = euclidean_distance(encoding, blocked_enc)
            if distance <= threshold:
                return True, f"match_distance_{distance:.3f}"
        except:
            continue
    
    return False, None


def verify_real(selfie_path: str, id_doc_path: str, blocked_list: list) -> dict:
    """Verificación real con face_recognition"""
    
    if not os.path.exists(selfie_path):
        return {"status": "error", "message": "Selfie no encontrada", "encoding": None}
    if not os.path.exists(id_doc_path):
        return {"status": "error", "message": "Documento no encontrado", "encoding": None}
    
    # Extraer encodings
    selfie_enc = get_face_encoding(selfie_path)
    if not selfie_enc:
        return {"status": "no_face", "message": "No se detectó rostro en la selfie", "encoding": None}
    
    id_enc = get_face_encoding(id_doc_path)
    if not id_enc:
        return {"status": "no_face_id", "message": "No se detectó rostro en el documento", "encoding": None}
    
    # Verificar contra bloqueados (rápido: O(n * 128))
    is_blocked, match_info = check_against_blocked(selfie_enc, blocked_list)
    if is_blocked:
        return {"status": "blocked", "message": "Cuenta suspendida por violaciones previas", "encoding": selfie_enc}
    
    # Comparar selfie vs documento
    distance = euclidean_distance(selfie_enc, id_enc)
    if distance <= 0.6:  # Umbral típico para mismo rostro
        return {"status": "verified", "message": "Verificación biométrica exitosa", "encoding": selfie_enc}
    else:
        return {"status": "mismatch", "message": "El rostro no coincide con el documento", "encoding": None}


def verify_mock(selfie_path: str, id_doc_path: str, blocked_list: list) -> dict:
    """Modo simulación cuando face_recognition no está disponible"""
    
    time.sleep(0.3)  # Simular procesamiento
    
    if not os.path.exists(selfie_path):
        return {"status": "error", "message": "Selfie no encontrada", "encoding": None}
    if not os.path.exists(id_doc_path):
        return {"status": "error", "message": "Documento no encontrado", "encoding": None}
    
    # Generar encoding simulado (128 floats normalizados)
    mock_encoding = [random.gauss(0, 0.1) for _ in range(128)]
    
    # Verificar contra bloqueados (incluso en mock)
    is_blocked, _ = check_against_blocked(mock_encoding, blocked_list, threshold=0.3)
    if is_blocked:
        return {"status": "blocked", "message": "Cuenta suspendida", "encoding": mock_encoding}
    
    return {"status": "verified", "message": "Verificación exitosa (simulación)", "encoding": mock_encoding}


def main():
    if len(sys.argv) < 3:
        print(json.dumps({"status": "error", "message": "Argumentos insuficientes", "encoding": None}))
        sys.exit(1)
    
    selfie_path = sys.argv[1]
    id_doc_path = sys.argv[2]
    
    # Parse lista de bloqueados
    blocked_list = []
    if len(sys.argv) > 3:
        try:
            blocked_list = json.loads(sys.argv[3])
        except:
            pass
    
    # Ejecutar verificación
    result = verify_real(selfie_path, id_doc_path, blocked_list) if FACE_RECOGNITION_AVAILABLE \
             else verify_mock(selfie_path, id_doc_path, blocked_list)
    
    print(json.dumps(result))


if __name__ == "__main__":
    main()
