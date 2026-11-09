// OCR local con el framework Vision de macOS (sólo desarrollo en Mac). Uso: swift ocr-macos.swift <imagen>
// Imprime JSON: [{"text": "...", "confidence": 0.0-1.0}] ordenado de arriba hacia abajo.
import Foundation
import Vision
import AppKit

guard CommandLine.arguments.count > 1, let image = NSImage(contentsOfFile: CommandLine.arguments[1]),
      let cg = image.cgImage(forProposedRect: nil, context: nil, hints: nil) else {
    FileHandle.standardError.write("imagen ilegible\n".data(using: .utf8)!); exit(2)
}
let request = VNRecognizeTextRequest()
request.recognitionLevel = .accurate
request.usesLanguageCorrection = false
request.recognitionLanguages = ["es-ES", "en-US"]
try VNImageRequestHandler(cgImage: cg).perform([request])
let lines = (request.results ?? []).sorted { $0.boundingBox.minY > $1.boundingBox.minY }.compactMap { obs -> [String: Any]? in
    guard let top = obs.topCandidates(1).first else { return nil }
    return ["text": top.string, "confidence": Double(top.confidence)]
}
print(String(data: try JSONSerialization.data(withJSONObject: lines), encoding: .utf8)!)
