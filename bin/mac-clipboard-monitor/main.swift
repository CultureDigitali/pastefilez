import Cocoa
import Foundation
import CommonCrypto

final class PasteboardMonitor: NSObject {
    private var lastChangeCount: Int = NSPasteboard.general.changeCount
    private var lastHashes: Set<String> = []
    private var pollTimer: Timer?
    private let appServer: String
    private let apiToken: String
    private let defaults = UserDefaults.standard
    private let statusItem = NSStatusBar.system.statusItem(withLength: NSStatusItem.variableLength)
    private let menu = NSMenu()

    override init() {
        self.appServer = (defaults.string(forKey: "server") ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        self.apiToken = (defaults.string(forKey: "api_token") ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        super.init()
        if self.appServer.isEmpty || self.apiToken.isEmpty {
            self.openSettings()
        }
        configureMenu()
        start()
    }

    func applicationDidFinishLaunching(_ notification: Notification) {
        _ = PasteboardMonitor()
    }

    private func configureMenu() {
        statusItem.button?.title = "📋"
        statusItem.menu = menu
        let s1 = NSMenuItem(title: "Status: " + (appServer.isEmpty ? "Not configured" : "Active"), action: nil, keyEquivalent: "")
        s1.isEnabled = false
        menu.addItem(s1)
        menu.addItem(NSMenuItem.separator())
        let pause = NSMenuItem(title: "Pause", action: #selector(togglePause), keyEquivalent: "p")
        pause.target = self
        menu.addItem(pause)
        let cfg = NSMenuItem(title: "Settings…", action: #selector(openSettings), keyEquivalent: ",")
        cfg.target = self
        menu.addItem(cfg)
        menu.addItem(NSMenuItem.separator())
        let quit = NSMenuItem(title: "Quit", action: #selector(NSApplication.terminate(_:)), keyEquivalent: "q")
        menu.addItem(quit)
    }

    @objc private func togglePause() { }
    @objc private func openSettings() {
        let alert = NSAlert()
        alert.messageText = "PasteFilez Clipboard"
        alert.informativeText = "Configura server e API token."
        alert.addButton(withTitle: "Apri Impostazioni")
        alert.addButton(withTitle: "Esci")
        let r = alert.runModal()
        if r == .alertFirstButtonReturn {
            if let url = URL(string: "x-apple.systempreferences:com.apple.preference.users") {
                NSWorkspace.shared.open(url)
            }
            NSApp.terminate(nil)
        } else {
            NSApp.terminate(nil)
        }
    }

    private func start() {
        pollTimer = Timer.scheduledTimer(withTimeInterval: 1.0, repeats: true) { [weak self] _ in
            self?.poll()
        }
        RunLoop.main.add(pollTimer!, forMode: .common)
    }

    private func poll() {
        let pb = NSPasteboard.general
        if pb.changeCount == lastChangeCount { return }
        lastChangeCount = pb.changeCount
        for type in [NSPasteboard.PasteboardType.png, NSPasteboard.PasteboardType.tiff, .fileURL, .string] {
            if let data = pb.data(forType: type) {
                handle(data: data, type: type, pb: pb)
                return
            }
        }
    }

    private func handle(data: Data, type: NSPasteboard.PasteboardType, pb: NSPasteboard) {
        let hash = sha256(data)
        if lastHashes.contains(hash) { return }
        lastHashes.insert(hash)
        if lastHashes.count > 200 { lastHashes.removeFirst() }
        var mime = "application/octet-stream"
        var filename = "clipboard-\(Int(Date().timeIntervalSince1970))"
        var payload: Data = data
        if type == .string, let s = String(data: data, encoding: .utf8) {
            payload = s.data(using: .utf8) ?? data
            mime = "text/plain"
            filename += ".txt"
        } else if type == .png || type == .tiff {
            mime = type == .png ? "image/png" : "image/tiff"
            filename += "." + (type == .png ? "png" : "tiff")
        } else if type == .fileURL, let url = URL(dataRepresentation: data, relativeTo: nil) {
            do {
                payload = try Data(contentsOf: url)
                mime = "application/octet-stream"
                filename = url.lastPathComponent
            } catch {
                return
            }
        }
        upload(data: payload, mime: mime, filename: filename)
    }

    private func upload(data: Data, mime: String, filename: String) {
        guard let url = URL(string: appServer + "/api/v1/clipboard") else { return }
        var req = URLRequest(url: url)
        req.httpMethod = "POST"
        let boundary = "----PZ" + UUID().uuidString
        req.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        req.setValue("Bearer \(apiToken)", forHTTPHeaderField: "Authorization")
        var body = Data()
        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"original_name\"\r\n\r\n".data(using: .utf8)!)
        body.append("\(filename)\r\n".data(using: .utf8)!)
        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"file\"; filename=\"\(filename)\"\r\n".data(using: .utf8)!)
        body.append("Content-Type: \(mime)\r\n\r\n".data(using: .utf8)!)
        body.append(data)
        body.append("\r\n--\(boundary)--\r\n".data(using: .utf8)!)
        req.httpBody = body
        URLSession.shared.dataTask(with: req) { _, resp, err in
            if let err = err { NSLog("upload err: \(err)") }
            if let http = resp as? HTTPURLResponse, http.statusCode == 401 {
                self.openSettings()
            }
        }.resume()
    }

    private func sha256(_ data: Data) -> String {
        var h = [UInt8](repeating: 0, count: 32)
        data.withUnsafeBytes { _ = CC_SHA256($0.baseAddress, CC_LONG(data.count), &h) }
        return h.map { String(format: "%02x", $0) }.joined()
    }
}

let app = NSApplication.shared
let delegate = PasteboardMonitor()
app.delegate = delegate
_ = NSApplicationMain(CommandLine.argc, CommandLine.unsafeArgv)
